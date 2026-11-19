<?php

use App\Concerns\InteractsWithDataTable;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Setting;
use App\Models\User;
use App\Services\StudentIdCardService;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Student Identity Cards')] class extends Component {
    use InteractsWithDataTable, WithPagination;

    public string $batchFilter = '';
    public string $courseFilter = '';
    public string $sectionFilter = '';
    public string $optionalSubjectFilter = '';

    /** Bulk selected user IDs */
    public array $selectedStudentIds = [];
    public bool $selectAll = false;

    /** Preview Card Modal State */
    public bool $showPreviewModal = false;
    public ?User $previewStudent = null;
    public ?array $previewCardPayload = null;

    private const SEARCHABLE = ['name', 'student_id', 'email', 'phone', 'tenth_roll'];

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedStudentIds = $this->studentsQuery()->pluck('users.id')->map(fn ($id) => (string) $id)->all();
        } else {
            $this->selectedStudentIds = [];
        }
    }

    public function openPreview(int $studentId): void
    {
        $this->previewStudent = User::with([
            'studentProfile',
            'studentAddresses',
            'studentOtherDetails',
            'activeEnrollments.batch',
            'activeEnrollments.course',
            'activeEnrollments.section',
        ])->find($studentId);

        if ($this->previewStudent) {
            $this->previewCardPayload = StudentIdCardService::getCardPayload($this->previewStudent);
            $this->showPreviewModal = true;
        }
    }

    public function toggleStudentDownload(): void
    {
        $current = StudentIdCardService::isDownloadEnabledForStudents();
        $new = ! $current;
        Setting::set('student_id_card.enable_student_download', $new, Setting::TYPE_BOOLEAN, 'student_id_card');

        Toast::dispatch($this, 'success', $new
            ? __('Student ID card download is now enabled for student portal.')
            : __('Student ID card download has been restricted for students.'));
    }

    #[Computed]
    public function isDownloadEnabled(): bool
    {
        return StudentIdCardService::isDownloadEnabledForStudents();
    }

    #[Computed]
    public function batches()
    {
        return Batch::query()->orderBy('name')->get();
    }

    #[Computed]
    public function courses()
    {
        return Course::query()->orderBy('name')->get();
    }

    #[Computed]
    public function sections()
    {
        return Section::query()->orderBy('name')->get();
    }

    #[Computed]
    public function stats(): array
    {
        $totalStudents = User::role('Student')->count();
        $withOptional = User::role('Student')->whereHas('studentOtherDetails', fn ($q) => $q->whereNotNull('optional_subject'))->count();
        $activeEnrolled = User::role('Student')->whereHas('activeEnrollments')->count();

        return [
            'total' => $totalStudents,
            'enrolled' => $activeEnrolled,
            'with_optional' => $withOptional,
            'download_enabled' => StudentIdCardService::isDownloadEnabledForStudents(),
        ];
    }

    public function studentsQuery(): Builder
    {
        return User::query()
            ->role('Student')
            ->with([
                'studentProfile',
                'studentOtherDetails',
                'activeEnrollments.batch',
                'activeEnrollments.course',
                'activeEnrollments.section',
            ])
            ->when($this->batchFilter, fn ($q) => $q->whereHas('enrollments', fn ($eq) => $eq->where('batch_id', $this->batchFilter)))
            ->when($this->courseFilter, fn ($q) => $q->whereHas('enrollments', fn ($eq) => $eq->where('course_id', $this->courseFilter)))
            ->when($this->sectionFilter, fn ($q) => $q->whereHas('enrollments', fn ($eq) => $eq->where('section_id', $this->sectionFilter)))
            ->when($this->optionalSubjectFilter, fn ($q) => $q->whereHas('studentOtherDetails', fn ($oq) => $oq->where('optional_subject', $this->optionalSubjectFilter)))
            ->latest('id');
    }

    #[Computed]
    public function students()
    {
        return $this->paginateDataTable(
            $this->applyDataTable($this->studentsQuery(), self::SEARCHABLE)
        );
    }

    public function export(string $format = 'xlsx')
    {
        $this->authorize('student_id_cards.view');

        $headings = [
            'Student ID',
            'Full Name',
            'Email',
            'Phone',
            'Course',
            'Batch',
            'Section',
            'Roll Number',
            'Optional Subject',
            'Blood Group',
            'Account Status',
        ];

        return TableExporter::download(
            query: $this->studentsQuery(),
            format: $format,
            filename: 'student-id-cards-registry',
            headings: $headings,
            mapper: function (User $s): array {
                $enr = $s->activeEnrollments->first() ?? $s->enrollments->first();
                return [
                    $s->student_id ?: 'N/A',
                    $s->name,
                    $s->email,
                    $s->phone ?: '—',
                    $enr?->course?->name ?? '—',
                    $enr?->batch?->name ?? '—',
                    $enr?->section?->name ?? '—',
                    $enr?->roll_no ?: '—',
                    $s->studentOtherDetails?->optional_subject ?: 'Not Selected',
                    $s->studentOtherDetails?->dynamic_fields['blood_group'] ?? 'O+',
                    ucfirst((string) $s->status),
                ];
            },
            title: 'Student ID Cards Registry'
        );
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center p-2 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
                    <x-icon name="id-card" class="w-6 h-6" />
                </span>
                {{ __('Student Identity Cards Registry') }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Manage, preview, and generate official printable ID cards for all enrolled candidates.') }}
            </p>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            <button
                type="button"
                wire:click="toggleStudentDownload"
                class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border transition {{ $this->isDownloadEnabled ? 'border-emerald-300 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300' : 'border-rose-300 dark:border-rose-800 bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300' }}"
                title="{{ __('Click to toggle student portal download availability') }}"
            >
                <span class="w-2 h-2 rounded-full {{ $this->isDownloadEnabled ? 'bg-emerald-500 animate-pulse' : 'bg-rose-500' }}"></span>
                <span>{{ $this->isDownloadEnabled ? __('Student Download: ACTIVE') : __('Student Download: DISABLED') }}</span>
            </button>

            <a
                href="{{ route('admin.settings.student-id-card') }}"
                class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition"
            >
                <x-icon name="cog-6-tooth" class="w-4 h-4" />
                {{ __('Card Settings') }}
            </a>

            @if(count($selectedStudentIds) > 0)
                <a
                    href="{{ route('admin.student-id-cards.bulk-download', ['ids' => implode(',', $selectedStudentIds)]) }}"
                    target="_blank"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white shadow-xs transition"
                >
                    <x-icon name="arrow-down-tray" class="w-4 h-4" />
                    {{ __('Download Selected (:count)', ['count' => count($selectedStudentIds)]) }}
                </a>
            @endif

            <!-- Export Dropdown -->
            <div class="flex items-center rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-0.5 text-xs">
                <button wire:click="export('xlsx')" class="px-2.5 py-1.5 font-medium hover:text-primary transition">{{ __('Excel') }}</button>
                <span class="text-zinc-300 dark:text-zinc-700">|</span>
                <button wire:click="export('csv')" class="px-2.5 py-1.5 font-medium hover:text-primary transition">{{ __('CSV') }}</button>
                <span class="text-zinc-300 dark:text-zinc-700">|</span>
                <button wire:click="export('pdf')" class="px-2.5 py-1.5 font-medium hover:text-primary transition">{{ __('PDF') }}</button>
            </div>
        </div>
    </div>

    <!-- Stats Overview Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-4 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500">{{ __('Total Candidates') }}</span>
                <span class="p-2 rounded-xl bg-blue-500/10 text-blue-600"><x-icon name="users" class="w-4 h-4" /></span>
            </div>
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mt-2">{{ number_format($this->stats['total']) }}</div>
            <span class="text-[11px] text-zinc-400 mt-1 block">{{ __('Registered student profiles') }}</span>
        </div>

        <div class="p-4 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500">{{ __('Active Enrollments') }}</span>
                <span class="p-2 rounded-xl bg-emerald-500/10 text-emerald-600"><x-icon name="academic-cap" class="w-4 h-4" /></span>
            </div>
            <div class="text-2xl font-bold text-emerald-600 mt-2">{{ number_format($this->stats['enrolled']) }}</div>
            <span class="text-[11px] text-zinc-400 mt-1 block">{{ __('Currently assigned to batches') }}</span>
        </div>

        <div class="p-4 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500">{{ __('Optional Subject Assigned') }}</span>
                <span class="p-2 rounded-xl bg-purple-500/10 text-purple-600"><x-icon name="book-open" class="w-4 h-4" /></span>
            </div>
            <div class="text-2xl font-bold text-purple-600 mt-2">{{ number_format($this->stats['with_optional']) }}</div>
            <span class="text-[11px] text-zinc-400 mt-1 block">{{ __('With UPSC optional subject') }}</span>
        </div>

        <div class="p-4 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500">{{ __('Student Portal Download') }}</span>
                <span class="p-2 rounded-xl {{ $this->stats['download_enabled'] ? 'bg-emerald-500/10 text-emerald-600' : 'bg-rose-500/10 text-rose-600' }}">
                    <x-icon name="{{ $this->stats['download_enabled'] ? 'check-circle' : 'no-symbol' }}" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-lg font-bold {{ $this->stats['download_enabled'] ? 'text-emerald-600' : 'text-rose-600' }} mt-2">
                {{ $this->stats['download_enabled'] ? __('Enabled') : __('Disabled') }}
            </div>
            <span class="text-[11px] text-zinc-400 mt-1 block">{{ __('Controlled via admin settings') }}</span>
        </div>
    </div>

    <!-- Search & Filters Toolbar -->
    <div class="p-4 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <div class="lg:col-span-2">
                <x-ui.input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search candidate by name, student ID, roll no, email, phone...') }}"
                    icon="magnifying-glass"
                />
            </div>

            <select wire:model.live="batchFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                <option value="">{{ __('All Batches') }}</option>
                @foreach($this->batches as $batch)
                    <option value="{{ $batch->id }}">{{ $batch->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="courseFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                <option value="">{{ __('All Courses') }}</option>
                @foreach($this->courses as $course)
                    <option value="{{ $course->id }}">{{ $course->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="sectionFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none">
                <option value="">{{ __('All Sections') }}</option>
                @foreach($this->sections as $sec)
                    <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <!-- Students ID Cards Table -->
    <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 uppercase tracking-wider font-semibold">
                    <tr>
                        <th class="px-4 py-3 w-8">
                            <input type="checkbox" wire:model.live="selectAll" class="rounded border-zinc-300 dark:border-zinc-700 text-blue-600 focus:ring-blue-500" />
                        </th>
                        <th class="px-4 py-3">{{ __('Student / Identity') }}</th>
                        <th class="px-4 py-3">{{ __('Course & Batch') }}</th>
                        <th class="px-4 py-3">{{ __('Roll / Section') }}</th>
                        <th class="px-4 py-3">{{ __('Optional Subject') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($this->students as $student)
                        @php
                            $enr = $student->activeEnrollments->first() ?? $student->enrollments->first();
                            $optionalSub = $student->studentOtherDetails?->optional_subject;
                        @endphp
                        <tr class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40 transition">
                            <td class="px-4 py-3">
                                <input
                                    type="checkbox"
                                    wire:model.live="selectedStudentIds"
                                    value="{{ (string) $student->id }}"
                                    class="rounded border-zinc-300 dark:border-zinc-700 text-blue-600 focus:ring-blue-500"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 flex items-center justify-center overflow-hidden shrink-0">
                                        @if($student->avatarUrl())
                                            <img src="{{ $student->avatarUrl() }}" alt="{{ $student->name }}" class="w-full h-full object-cover" />
                                        @else
                                            <span class="text-xs font-bold text-zinc-500">{{ substr($student->name, 0, 1) }}</span>
                                        @endif
                                    </div>
                                    <div>
                                        <div class="font-bold text-zinc-900 dark:text-zinc-100">{{ $student->name }}</div>
                                        <div class="flex items-center gap-1.5 mt-0.5">
                                            <span class="font-mono text-[10px] text-blue-600 dark:text-blue-400 font-semibold">ID: {{ $student->student_id ?: 'N/A' }}</span>
                                            <span class="text-zinc-400">&bull;</span>
                                            <span class="text-[11px] text-zinc-500">{{ $student->email }}</span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $enr?->course?->name ?? '—' }}</div>
                                <div class="text-[11px] text-zinc-500">{{ $enr?->batch?->name ?? '—' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-mono font-bold text-zinc-800 dark:text-zinc-200">{{ $enr?->roll_no ?: '—' }}</div>
                                <div class="text-[11px] text-zinc-500">{{ $enr?->section?->name ?? '—' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                @if($optionalSub)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold bg-purple-50 dark:bg-purple-950/40 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800/60">
                                        {{ $optionalSub }}
                                    </span>
                                @else
                                    <span class="text-[11px] text-zinc-400 italic">{{ __('Not Selected') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        wire:click="openPreview({{ $student->id }})"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 transition"
                                        title="{{ __('Preview Digital Card') }}"
                                    >
                                        <x-icon name="eye" class="w-3.5 h-3.5 text-zinc-500" />
                                        <span>{{ __('Preview') }}</span>
                                    </button>

                                    <a
                                        href="{{ route('student.id-card.download', ['student' => $student->id]) }}"
                                        target="_blank"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition shadow-xs"
                                        title="{{ __('Download Printable PDF') }}"
                                    >
                                        <x-icon name="arrow-down-tray" class="w-3.5 h-3.5" />
                                        <span>{{ __('PDF') }}</span>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-zinc-400 italic">
                                {{ __('No students found matching current filters.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
            {{ $this->students->links() }}
        </div>
    </div>

    <!-- Card Preview Modal Dialog -->
    @if($showPreviewModal && $previewCardPayload)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-2xl rounded-3xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 sm:p-7 space-y-6 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                    <div>
                        <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                            <x-icon name="id-card" class="w-5 h-5 text-blue-600" />
                            {{ __('Student Identity Card Preview') }}
                        </h3>
                        <p class="text-xs text-zinc-500 mt-0.5">
                            {{ $previewCardPayload['name'] }} &bull; {{ $previewCardPayload['student_id'] }}
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="$set('showPreviewModal', false)"
                        class="p-1 rounded-lg text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200"
                    >
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <!-- Card Front Preview -->
                <div class="rounded-2xl bg-gradient-to-br from-blue-950 via-slate-900 to-indigo-950 p-6 text-white shadow-xl space-y-4 relative overflow-hidden">
                    <div class="border-b border-white/15 pb-3 flex items-start justify-between">
                        <div>
                            <div class="font-bold text-sm tracking-wide uppercase">{{ $previewCardPayload['settings']['institution_name'] }}</div>
                            <div class="text-[10px] text-blue-200">{{ $previewCardPayload['settings']['institution_sub'] }}</div>
                        </div>
                        <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-white/20 text-blue-200">
                            {{ $previewCardPayload['settings']['card_title'] }}
                        </span>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-5 items-center sm:items-start">
                        <div class="w-24 h-32 rounded-xl bg-white/10 border-2 border-white/20 flex items-center justify-center shrink-0 overflow-hidden">
                            @if($previewCardPayload['photo_url'])
                                <img src="{{ $previewCardPayload['photo_url'] }}" alt="{{ $previewCardPayload['name'] }}" class="w-full h-full object-cover" />
                            @else
                                <x-icon name="user" class="w-10 h-10 text-white/50" />
                            @endif
                        </div>

                        <div class="flex-1 space-y-1.5 text-center sm:text-left">
                            <div class="text-xl font-bold text-white">{{ $previewCardPayload['name'] }}</div>
                            <div class="inline-block px-2 py-0.5 rounded bg-white/15 font-mono text-xs text-blue-200 font-semibold">
                                ID: {{ $previewCardPayload['student_id'] }} &bull; Roll: {{ $previewCardPayload['roll_no'] }}
                            </div>
                            <div class="text-xs text-zinc-300">
                                <div><strong class="text-blue-200">Course:</strong> {{ $previewCardPayload['course'] }}</div>
                                <div><strong class="text-blue-200">Batch:</strong> {{ $previewCardPayload['batch'] }} ({{ $previewCardPayload['section'] }})</div>
                                @if($previewCardPayload['settings']['show_optional_subject'])
                                    <div class="text-amber-300 font-semibold"><strong class="text-blue-200">Optional:</strong> {{ $previewCardPayload['optional_subject'] }}</div>
                                @endif
                            </div>
                        </div>

                        @if($previewCardPayload['settings']['show_qr_code'])
                            <div class="p-2 bg-white rounded-xl shrink-0">
                                <img src="{{ $previewCardPayload['qr_uri'] }}" class="w-16 h-16" alt="QR" />
                            </div>
                        @endif
                    </div>

                    @if($previewCardPayload['settings']['show_barcode'])
                        <div class="pt-3 border-t border-white/15 flex items-center justify-between">
                            <img src="{{ $previewCardPayload['barcode_uri'] }}" class="h-8 bg-white p-1 rounded" alt="Barcode" />
                            <span class="text-[10px] font-mono text-blue-300">{{ $previewCardPayload['student_id'] }}</span>
                        </div>
                    @endif
                </div>

                <!-- Actions Footer -->
                <div class="flex items-center justify-end gap-3 pt-2">
                    <x-ui.button
                        type="button"
                        wire:click="$set('showPreviewModal', false)"
                        variant="outline"
                        class="text-xs"
                    >
                        {{ __('Close') }}
                    </x-ui.button>

                    <a
                        href="{{ route('student.id-card.download', ['student' => $previewStudent->id]) }}"
                        target="_blank"
                        class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white shadow-xs transition"
                    >
                        <x-icon name="arrow-down-tray" class="w-4 h-4" />
                        {{ __('Download Official PDF Card') }}
                    </a>
                </div>
            </div>
        </div>
    @endif
</div>

