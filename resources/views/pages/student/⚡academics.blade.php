<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseWithdrawalRequest;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Academics & Enrolments')] class extends Component {
    use WithFileUploads;

    #[Url(as: 'tab')]
    public string $activeTab = 'all'; // 'all', 'active', 'completed', 'withdrawn', 'history'

    // Course Withdrawal / Discontinuation Modal State
    public ?int $withdrawalEnrollmentId = null;
    public ?Enrollment $withdrawalEnrollment = null;
    public array $withdrawalForm = [
        'request_type' => 'withdrawal',
        'reason_category' => 'health_medical',
        'reason_description' => '',
    ];
    public $withdrawalDocument = null;

    #[Computed]
    public function student(): User
    {
        return auth()->user()->load(['studentProfile', 'studentAddresses']);
    }

    #[Computed]
    public function enrollments()
    {
        return Enrollment::with(['course', 'batch', 'section', 'pendingWithdrawalRequest'])
            ->where('user_id', auth()->id())
            ->when($this->activeTab === 'active', fn (Builder $q) => $q->where('status', 'active'))
            ->when($this->activeTab === 'completed', fn (Builder $q) => $q->where('status', 'completed'))
            ->when($this->activeTab === 'withdrawn', fn (Builder $q) => $q->whereIn('status', ['withdrawn', 'discontinued', 'cancelled']))
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function withdrawalHistory()
    {
        return CourseWithdrawalRequest::with(['course', 'batch', 'enrollment', 'reviewer'])
            ->where('user_id', auth()->id())
            ->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function stats(): array
    {
        $all = Enrollment::where('user_id', auth()->id())->get();

        return [
            'total' => $all->count(),
            'active' => $all->where('status', 'active')->count(),
            'completed' => $all->where('status', 'completed')->count(),
            'withdrawn' => $all->whereIn('status', ['withdrawn', 'discontinued', 'cancelled'])->count(),
            'history' => CourseWithdrawalRequest::where('user_id', auth()->id())->count(),
        ];
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function openWithdrawalModal(int $enrollmentId): void
    {
        $this->withdrawalEnrollmentId = $enrollmentId;
        $this->withdrawalEnrollment = Enrollment::with(['course', 'batch', 'section'])
            ->where('user_id', auth()->id())
            ->findOrFail($enrollmentId);

        $this->withdrawalForm = [
            'request_type' => 'withdrawal',
            'reason_category' => 'health_medical',
            'reason_description' => '',
        ];
        $this->withdrawalDocument = null;
        $this->resetValidation();
        $this->dispatch('modal-open', name: 'course-withdrawal-modal');
    }

    public function closeWithdrawalModal(): void
    {
        $this->withdrawalEnrollmentId = null;
        $this->withdrawalEnrollment = null;
        $this->withdrawalDocument = null;
        $this->resetValidation();
        $this->dispatch('modal-close', name: 'course-withdrawal-modal');
    }

    public function clearWithdrawalDocument(): void
    {
        $this->withdrawalDocument = null;
    }

    public function submitWithdrawalRequest(): void
    {
        $this->validate([
            'withdrawalForm.request_type' => 'required|in:withdrawal,discontinuation',
            'withdrawalForm.reason_category' => 'required|string|max:100',
            'withdrawalForm.reason_description' => 'required|string|min:10|max:1500',
            'withdrawalDocument' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if (! $this->withdrawalEnrollmentId) {
            return;
        }

        $enr = Enrollment::where('user_id', auth()->id())->findOrFail($this->withdrawalEnrollmentId);

        $existingPending = CourseWithdrawalRequest::where('enrollment_id', $enr->id)
            ->where('status', 'pending')
            ->exists();

        if ($existingPending) {
            Toast::dispatch($this, 'error', __('A withdrawal request for this course is already pending review.'));
            return;
        }

        DB::beginTransaction();
        try {
            $docPath = null;
            if ($this->withdrawalDocument) {
                $docPath = $this->withdrawalDocument->store('withdrawal_documents/'.auth()->id(), 'public');
            }

            $req = CourseWithdrawalRequest::create([
                'student_id' => auth()->id(),
                'course_id' => $enr->course_id,
                'batch_id' => $enr->batch_id,
                'enrollment_id' => $enr->id,
                'request_type' => $this->withdrawalForm['request_type'],
                'reason_category' => $this->withdrawalForm['reason_category'],
                'reason_description' => $this->withdrawalForm['reason_description'],
                'supporting_document_path' => $docPath,
                'status' => 'pending',
                'requested_at' => now(),
            ]);

            AuditLog::record(
                action: 'course_withdrawal.submitted',
                details: [
                    'request_id' => $req->id,
                    'request_number' => $req->request_number,
                    'course_id' => $enr->course_id,
                    'course_name' => $enr->course?->name,
                    'batch_name' => $enr->batch?->name,
                    'reason_category' => $this->withdrawalForm['reason_category'],
                ],
                userId: auth()->id(),
                type: 'course_withdrawal',
                status: 'success'
            );

            DB::commit();

            Toast::dispatch($this, 'success', __('Your course withdrawal request (:no) has been submitted for administrative review.', [
                'no' => $req->request_number,
            ]));

            $this->closeWithdrawalModal();
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to submit withdrawal request: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function cancelWithdrawalRequest(int $requestId): void
    {
        $req = CourseWithdrawalRequest::where('user_id', auth()->id())
            ->where('status', 'pending')
            ->findOrFail($requestId);

        DB::beginTransaction();
        try {
            $req->update([
                'status' => 'withdrawn',
                'admin_notes' => 'Cancelled by student on '.now()->format('d M Y, h:i A'),
            ]);

            AuditLog::record(
                action: 'course_withdrawal.cancelled_by_student',
                details: [
                    'request_id' => $req->id,
                    'request_number' => $req->request_number,
                ],
                userId: auth()->id(),
                type: 'course_withdrawal',
                status: 'success'
            );

            DB::commit();
            Toast::dispatch($this, 'success', __('Withdrawal request cancelled successfully.'));
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to cancel request: :msg', ['msg' => $e->getMessage()]));
        }
    }
}; ?>

<div class="space-y-6">
    <!-- Header with Identity Summary -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('student.dashboard') }}" wire:navigate class="p-1 rounded-lg hover:bg-muted text-muted-foreground hover:text-foreground transition-colors" title="{{ __('Back to Dashboard') }}">
                    <x-icon name="arrow-left" class="w-5 h-5" />
                </a>
                <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                    <x-icon name="graduation-cap" class="w-7 h-7 text-primary" />
                    {{ __('Academics & Enrolments') }}
                </h1>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Official record of academic admissions, enrolled courses, section allocations, and course withdrawal history.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.badge variant="outline" class="font-mono text-xs px-3 py-1.5 gap-1.5" title="{{ __('Universal Registration Number') }}">
                <span class="text-muted-foreground">{{ __('URN:') }}</span>
                @if(!empty($this->student->urn))
                    <span class="font-bold text-foreground">{{ $this->student->urn }}</span>
                @else
                    <span class="text-muted-foreground/70 italic text-[11px]">{{ __('Not Assigned') }}</span>
                @endif
            </x-ui.badge>
            <x-ui.badge variant="secondary" class="font-mono text-xs px-3 py-1.5 gap-1.5">
                <span class="text-muted-foreground">{{ __('Student ID:') }}</span>
                <span class="font-bold text-primary">{{ $this->student->student_id ?: __('Pending Generation') }}</span>
            </x-ui.badge>
            <x-ui.badge variant="outline" class="text-xs px-3 py-1.5">
                {{ __('Category: ') }}<strong>{{ $this->student->studentProfile?->category ?: 'UR' }}</strong>
            </x-ui.badge>

            <a href="{{ route('student.routine.index') }}" wire:navigate class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow-2xs transition">
                <x-icon name="calendar" class="w-3.5 h-3.5" />
                <span>{{ __('Class Routine') }}</span>
            </a>

            <a href="{{ route('student.id-card.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white shadow-2xs transition">
                <x-icon name="id-card" class="w-3.5 h-3.5" />
                <span>{{ __('Student ID Card') }}</span>
            </a>
        </div>
    </div>

    <!-- Student Identity & Cohort Details Card -->
    <div class="bg-card border border-border rounded-xl p-5 shadow-xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-border">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-lg uppercase">
                    {{ substr($this->student->first_name ?: $this->student->name, 0, 1) }}{{ substr($this->student->last_name ?: '', 0, 1) }}
                </div>
                <div>
                    <h2 class="text-base font-bold text-foreground">{{ $this->student->name }}</h2>
                    <p class="text-xs text-muted-foreground">
                        {{ $this->student->email }} &bull; {{ $this->student->phone ?: __('No phone') }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="font-mono text-xs text-muted-foreground bg-muted/60 px-2.5 py-1 rounded-md border border-border/50">
                    {{ __('URN:') }}
                    @if(!empty($this->student->urn))
                        <strong class="text-foreground">{{ $this->student->urn }}</strong>
                    @else
                        <span class="text-muted-foreground/70 italic text-[11px]">{{ __('Not Assigned') }}</span>
                    @endif
                </span>
                <span class="text-xs text-muted-foreground">{{ __('Total Enrolments:') }}</span>
                <span class="font-mono font-bold text-foreground text-sm">{{ $this->stats['total'] }}</span>
            </div>
        </div>

        <!-- Student ID Format / Cohort Explainer Bar -->
        <div class="p-3.5 bg-muted/40 border border-border rounded-lg text-xs space-y-2">
            <div class="flex items-center gap-2 font-semibold text-foreground">
                <x-icon name="info" class="w-4 h-4 text-primary shrink-0" />
                <span>{{ __('Official Student Identity Specification (9-Digit Cohort Identifier):') }}</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 pt-1">
                <div class="bg-card p-2.5 rounded border border-border/60">
                    <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Academic Year (4 Digits)') }}</span>
                    <span class="font-mono font-bold text-sm text-foreground">
                        {{ $this->student->student_id ? substr($this->student->student_id, 0, 4) : date('Y') }}
                    </span>
                    <span class="text-[10px] text-muted-foreground block mt-0.5">{{ __('Enrollment Year') }}</span>
                </div>
                <div class="bg-card p-2.5 rounded border border-border/60">
                    <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Cohort / Section (1 Digit)') }}</span>
                    <span class="font-mono font-bold text-sm text-primary">
                        {{ $this->student->student_id && strlen($this->student->student_id) >= 5 ? substr($this->student->student_id, 4, 1) : '1' }}
                    </span>
                    <span class="text-[10px] text-muted-foreground block mt-0.5">{{ __('1=Sec A, 2=Sec B, 3=PCC, 0=Unassigned') }}</span>
                </div>
                <div class="bg-card p-2.5 rounded border border-border/60">
                    <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Roll Sequence (4 Digits)') }}</span>
                    <span class="font-mono font-bold text-sm text-foreground">
                        {{ $this->student->student_id && strlen($this->student->student_id) >= 9 ? substr($this->student->student_id, 5, 4) : '0001' }}
                    </span>
                    <span class="text-[10px] text-muted-foreground block mt-0.5">{{ __('Unique Institutional Roll Index') }}</span>
                </div>
                <div class="bg-card p-2.5 rounded border border-purple-500/30 bg-purple-500/5">
                    <span class="text-[10px] uppercase font-bold text-purple-600 dark:text-purple-400 block">{{ __('Selected Optional Subject') }}</span>
                    <span class="font-bold text-sm text-foreground block truncate" title="{{ $this->student->studentOtherDetails?->optional_subject ?: __('Not Selected') }}">
                        {{ $this->student->studentOtherDetails?->optional_subject ?: __('Not Selected') }}
                    </span>
                    <a href="{{ route('student.dashboard', ['tab' => 'upsc']) }}" class="text-[10px] text-purple-600 dark:text-purple-400 hover:underline block mt-0.5 font-semibold">{{ __('Change / Request') }} &rarr;</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Navigation Tabs -->
    <div class="flex items-center justify-between border-b border-border gap-2 overflow-x-auto pb-1">
        <div class="flex items-center space-x-1 sm:space-x-2">
            <button
                type="button"
                wire:click="setTab('all')"
                class="px-3 py-2 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 cursor-pointer {{ $activeTab === 'all' ? 'border-primary text-primary font-bold' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="book-open" class="w-4 h-4" />
                <span>{{ __('All Enrolments') }}</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-muted text-foreground font-mono">{{ $this->stats['total'] }}</span>
            </button>

            <button
                type="button"
                wire:click="setTab('active')"
                class="px-3 py-2 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 cursor-pointer {{ $activeTab === 'active' ? 'border-primary text-primary font-bold' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="check-circle" class="w-4 h-4 text-emerald-600" />
                <span>{{ __('Active Courses') }}</span>
                @if($this->stats['active'] > 0)
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-emerald-500/10 text-emerald-600 font-mono font-bold">{{ $this->stats['active'] }}</span>
                @endif
            </button>

            <button
                type="button"
                wire:click="setTab('completed')"
                class="px-3 py-2 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 cursor-pointer {{ $activeTab === 'completed' ? 'border-primary text-primary font-bold' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="award" class="w-4 h-4" />
                <span>{{ __('Completed / Alumni') }}</span>
                @if($this->stats['completed'] > 0)
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-muted text-foreground font-mono">{{ $this->stats['completed'] }}</span>
                @endif
            </button>

            <button
                type="button"
                wire:click="setTab('history')"
                class="px-3 py-2 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 cursor-pointer {{ $activeTab === 'history' ? 'border-primary text-primary font-bold' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="history" class="w-4 h-4 text-amber-600" />
                <span>{{ __('Withdrawal & Discontinuation History') }}</span>
                @if($this->stats['history'] > 0)
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-amber-500/10 text-amber-600 font-mono font-bold">{{ $this->stats['history'] }}</span>
                @endif
            </button>
        </div>
    </div>

    <!-- CONTENT SECTION 1: COURSE ENROLMENTS -->
    @if($activeTab !== 'history')
        @php
            $enrList = $this->enrollments;
        @endphp

        @if($enrList->isEmpty())
            <div class="bg-card border border-border rounded-xl p-10 text-center shadow-xs">
                <div class="w-12 h-12 rounded-full bg-primary/10 text-primary flex items-center justify-center mx-auto mb-3">
                    <x-icon name="graduation-cap" class="w-6 h-6" />
                </div>
                <h3 class="text-base font-semibold text-foreground">{{ __('No Enrolment Records in this View') }}</h3>
                <p class="text-xs text-muted-foreground max-w-md mx-auto mt-1">
                    {{ __('There are no course enrollments matching this filter category. Switch to "All Enrolments" to view complete academic history.') }}
                </p>
                <div class="mt-4">
                    <x-ui.button size="sm" variant="outline" wire:click="setTab('all')">
                        {{ __('View All Enrolments') }}
                    </x-ui.button>
                </div>
            </div>
        @else
            <div class="space-y-4">
                @foreach($enrList as $enrollment)
                    @php
                        $course = $enrollment->course;
                        $batch = $enrollment->batch;
                        $section = $enrollment->section;
                        $isFreeCourse = ($course && $course->isFree()) || $enrollment->fee_type === 'free' || (float)$enrollment->fee_amount === 0.0;
                        $displayStudentId = $enrollment->student_id ?: $this->student->student_id;
                    @endphp
                    <div class="bg-card border {{ $isFreeCourse ? 'border-emerald-500/30' : 'border-border' }} rounded-xl p-5 shadow-xs space-y-4 transition-all hover:border-primary/40">
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 pb-3 border-b border-border">
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h3 class="text-base font-bold text-foreground">{{ $course?->name ?? __('Unknown Course') }}</h3>
                                    @if($course?->code)
                                        <x-ui.badge variant="secondary" class="font-mono text-xs">{{ $course->code }}</x-ui.badge>
                                    @endif
                                    @if($course?->category)
                                        <x-ui.badge variant="outline" class="text-xs">
                                            {{ \App\Helpers\CourseData::getCategoryLabel($course->category) }}
                                        </x-ui.badge>
                                    @endif
                                    @if($isFreeCourse)
                                        <x-ui.badge variant="success" class="text-xs">
                                            {{ __('Free of Cost / No Fee') }}
                                        </x-ui.badge>
                                    @endif
                                </div>
                                <div class="flex items-center gap-3 text-xs text-muted-foreground mt-1.5 flex-wrap">
                                    @if(!empty($this->student->urn))
                                        <x-ui.badge variant="outline" class="font-mono text-xs gap-1" title="{{ __('Universal Registration Number') }}">
                                            <span class="text-muted-foreground">{{ __('URN:') }}</span>
                                            <span class="font-bold text-foreground">{{ $this->student->urn }}</span>
                                        </x-ui.badge>
                                    @endif
                                    <x-ui.badge variant="secondary" class="font-mono text-xs gap-1">
                                        <span class="text-muted-foreground">{{ __('Student ID:') }}</span>
                                        <span class="font-bold text-primary">{{ $displayStudentId ?: __('Pending') }}</span>
                                    </x-ui.badge>
                                    <span>
                                        {{ __('Enrollment No:') }} <strong class="font-mono text-foreground">{{ $enrollment->enrollment_no }}</strong>
                                    </span>
                                    @if($enrollment->application_no)
                                        <span>&bull;</span>
                                        <span>
                                            {{ __('App No:') }} <strong class="font-mono text-foreground">{{ $enrollment->application_no }}</strong>
                                        </span>
                                    @endif
                                    <span>&bull;</span>
                                    <span>
                                        {{ __('Enrolled on:') }} {{ $enrollment->enrolled_at ? $enrollment->enrolled_at->format('d M Y') : $enrollment->created_at->format('d M Y') }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-ui.badge :variant="$enrollment->status === 'active' ? 'success' : ($enrollment->status === 'completed' ? 'secondary' : 'destructive')" class="text-xs capitalize font-semibold">
                                    {{ $enrollment->status }}
                                </x-ui.badge>
                            </div>
                        </div>

                        <!-- Grid Details -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                            <!-- Batch -->
                            <div class="p-3 rounded-lg bg-muted/30 border border-border/50">
                                <span class="text-muted-foreground font-medium block">{{ __('Academic Batch') }}</span>
                                <span class="text-sm font-bold text-foreground block mt-0.5">{{ $batch?->name ?? '—' }}</span>
                                <span class="text-[11px] text-muted-foreground block mt-0.5">
                                    {{ __('Year: ') }}{{ $batch?->academic_year ?? '—' }} &bull; {{ $course?->duration_months ? $course->duration_months . ' ' . __('Months') : ($course?->duration_weeks ? $course->duration_weeks . ' ' . __('Weeks') : '—') }}
                                </span>
                            </div>

                            <!-- Assigned Section -->
                            <div class="p-3 rounded-lg bg-muted/30 border border-border/50">
                                <span class="text-muted-foreground font-medium block">{{ __('Assigned Section & Cohort') }}</span>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <span class="text-sm font-bold text-foreground">{{ $section?->name ?? __('Unassigned') }}</span>
                                    @if($section)
                                        <x-ui.badge variant="primary" class="text-[10px] font-mono py-0">
                                            {{ __('ID Code: ') }}{{ $section->id_code ?: ($section->name === 'Section B' ? '2' : '1') }}
                                        </x-ui.badge>
                                    @endif
                                </div>
                                <span class="text-[11px] text-muted-foreground block mt-0.5">
                                    @if($section)
                                        {{ __('Capacity: ') }}{{ $section->capacity }} {{ __('seats') }}
                                        @if($section->room_no) &bull; {{ __('Room: ') }}{{ $section->room_no }} @endif
                                    @else
                                        {{ __('Cohort allocation by admin') }}
                                    @endif
                                </span>
                            </div>

                            <!-- Fee Structure -->
                            <div class="p-3 rounded-lg {{ $isFreeCourse ? 'bg-emerald-500/10 border border-emerald-500/20' : 'bg-muted/30 border border-border/50' }}">
                                <span class="text-muted-foreground font-medium block">{{ __('Fee Structure') }}</span>
                                <div class="mt-0.5">
                                    @if($isFreeCourse)
                                        <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400">₹0.00</span>
                                        <span class="text-[11px] text-emerald-700/80 dark:text-emerald-400/80 block mt-0.5">
                                            {{ __('100% Free / Sponsored') }}
                                        </span>
                                    @else
                                        <span class="text-sm font-bold text-foreground">₹{{ number_format((float) $enrollment->fee_amount, 2) }}</span>
                                        <span class="text-[11px] text-muted-foreground block mt-0.5 capitalize">
                                            {{ $course?->fee_type ? str_replace('_', ' ', $course->fee_type) : __('Standard Fee') }}
                                            ({{ $this->student->studentProfile?->category ?: 'UR' }} {{ __('Rate') }})
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <!-- Payment Status -->
                            <div class="p-3 rounded-lg bg-muted/30 border border-border/50">
                                <span class="text-muted-foreground font-medium block">{{ __('Payment Status') }}</span>
                                <div class="mt-0.5 flex items-center gap-1.5">
                                    @if($isFreeCourse || $enrollment->fee_status === 'exempted')
                                        <x-ui.badge variant="success" class="text-xs">
                                            {{ __('Exempted (Zero Fee)') }}
                                        </x-ui.badge>
                                    @elseif($enrollment->fee_status === 'paid')
                                        <x-ui.badge variant="success" class="text-xs">
                                            {{ __('Fully Paid') }}
                                        </x-ui.badge>
                                    @elseif($enrollment->fee_status === 'partial')
                                        <x-ui.badge variant="warning" class="text-xs">
                                            {{ __('Partially Paid') }}
                                        </x-ui.badge>
                                    @else
                                        <x-ui.badge variant="destructive" class="text-xs">
                                            {{ __('Pending Payment') }}
                                        </x-ui.badge>
                                    @endif
                                </div>
                                <span class="text-[11px] text-muted-foreground block mt-0.5">
                                    @if($isFreeCourse)
                                        {{ __('No student charges apply') }}
                                    @elseif($enrollment->fee_status === 'paid')
                                        {{ __('Receipt cleared') }}
                                    @else
                                        {{ __('Contact accounts desk') }}
                                    @endif
                                </span>
                            </div>
                        </div>

                        <!-- Pending Withdrawal Request Banner -->
                        @php
                            $pendingWth = $enrollment->pendingWithdrawalRequest;
                        @endphp

                        @if($pendingWth)
                            <div class="p-3.5 bg-amber-50 dark:bg-amber-950/30 border border-amber-300 dark:border-amber-800 rounded-lg flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs">
                                <div class="flex items-center gap-2 text-amber-900 dark:text-amber-200">
                                    <x-icon name="clock" class="w-4 h-4 text-amber-600 shrink-0" />
                                    <div>
                                        <strong>{{ __('Withdrawal / Discontinuation Requested:') }}</strong>
                                        <span class="font-mono font-bold">{{ $pendingWth->request_number }}</span> &bull; {{ $pendingWth->getReasonLabel() }}
                                        <span class="text-muted-foreground text-[11px]">({{ ($pendingWth->requested_at ?? $pendingWth->created_at)?->format('d M Y') }})</span>
                                    </div>
                                </div>
                                <x-ui.button size="xs" variant="outline" wire:click="cancelWithdrawalRequest({{ $pendingWth->id }})" class="text-xs text-rose-600 border-rose-300 hover:bg-rose-50 dark:hover:bg-rose-950/30">
                                    {{ __('Cancel Request') }}
                                </x-ui.button>
                            </div>
                        @elseif($enrollment->isWithdrawn() || $enrollment->isDiscontinued() || $enrollment->isCancelled())
                            <div class="p-3 bg-rose-50/70 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900/40 rounded-lg text-xs space-y-1">
                                <div class="font-bold text-rose-700 dark:text-rose-400 flex items-center gap-1.5">
                                    <x-icon name="alert-circle" class="w-4 h-4" />
                                    <span>{{ __('Course Status: :status', ['status' => ucfirst($enrollment->status)]) }}</span>
                                </div>
                                <p class="text-muted-foreground">
                                    {{ $enrollment->cancellation_reason ?: __('Discontinued / cancelled under official institutional record.') }}
                                    @if($enrollment->discontinued_at)
                                        &bull; {{ is_string($enrollment->discontinued_at) ? \Carbon\Carbon::parse($enrollment->discontinued_at)->format('d M Y') : $enrollment->discontinued_at?->format('d M Y') }}
                                    @endif
                                </p>
                            </div>
                        @elseif($enrollment->canRequestWithdrawal())
                            <div class="flex items-center justify-between pt-2 border-t border-border/40">
                                <div class="text-[11px] text-muted-foreground">
                                    {{ __('Need to exit or discontinue from this course programme?') }}
                                </div>
                                <x-ui.button size="xs" variant="outline" wire:click="openWithdrawalModal({{ $enrollment->id }})" class="text-xs gap-1.5 text-amber-600 hover:text-amber-700 border-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/30">
                                    <x-icon name="user-minus" class="w-3.5 h-3.5" />
                                    {{ __('Request Course Withdrawal / Discontinuation') }}
                                </x-ui.button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    <!-- CONTENT SECTION 2: DEDICATED WITHDRAWAL & DISCONTINUATION HISTORY -->
    @if($activeTab === 'history')
        @php
            $history = $this->withdrawalHistory;
        @endphp

        <div class="bg-card border border-border rounded-xl shadow-xs overflow-hidden">
            <div class="p-5 border-b border-border">
                <h3 class="text-base font-semibold text-foreground flex items-center gap-2">
                    <x-icon name="history" class="w-5 h-5 text-amber-600" />
                    {{ __('Course Withdrawal & Discontinuation History') }}
                </h3>
                <p class="text-xs text-muted-foreground mt-0.5">
                    {{ __('Complete chronicle of your submitted course exit requests, grounds, proof documents, and administrative review decisions.') }}
                </p>
            </div>

            @if($history->isEmpty())
                <div class="p-10 text-center text-xs text-muted-foreground">
                    <x-icon name="file-check" class="w-8 h-8 text-muted-foreground/60 mx-auto mb-2" />
                    <p class="font-semibold text-foreground">{{ __('No Withdrawal or Discontinuation Requests Filed') }}</p>
                    <p class="text-[11px] mt-0.5">{{ __('You have not submitted any exit or discontinuation requests. Your enrollment records remain in good standing.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase text-[10px] font-semibold tracking-wider">
                            <tr>
                                <th class="py-3 px-4">{{ __('Request #') }}</th>
                                <th class="py-3 px-4">{{ __('Type & Nature') }}</th>
                                <th class="py-3 px-4">{{ __('Course & Batch') }}</th>
                                <th class="py-3 px-4">{{ __('Stated Grounds') }}</th>
                                <th class="py-3 px-4">{{ __('Submitted On') }}</th>
                                <th class="py-3 px-4">{{ __('Status') }}</th>
                                <th class="py-3 px-4">{{ __('Admin Notes / Remarks') }}</th>
                                <th class="py-3 px-4 text-right">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($history as $item)
                                <tr class="hover:bg-muted/30 transition-colors">
                                    <td class="py-3.5 px-4 font-mono font-bold text-foreground whitespace-nowrap">
                                        {{ $item->request_number }}
                                    </td>
                                    <td class="py-3.5 px-4 whitespace-nowrap">
                                        <x-ui.badge :variant="$item->type === 'withdrawal' ? 'destructive' : 'warning'" class="text-[10px] uppercase font-bold">
                                            {{ $item->type === 'withdrawal' ? __('Withdrawal') : __('Discontinuation') }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <div class="font-semibold text-foreground">{{ $item->course?->name ?? '—' }}</div>
                                        <div class="text-[11px] text-muted-foreground">{{ $item->batch?->name }} ({{ $item->course?->code }})</div>
                                    </td>
                                    <td class="py-3.5 px-4 max-w-xs">
                                        <div class="font-medium text-foreground">{{ $item->getReasonLabel() }}</div>
                                        @if($item->reason_details)
                                            <p class="text-[11px] text-muted-foreground line-clamp-2 mt-0.5">{{ $item->reason_details }}</p>
                                        @endif
                                        @if($item->supporting_document)
                                            <a href="{{ Storage::disk('public')->url($item->supporting_document) }}" target="_blank" class="inline-flex items-center gap-1 text-[11px] text-primary hover:underline mt-1">
                                                <x-icon name="paperclip" class="w-3 h-3" />
                                                {{ __('Attached Proof') }}
                                            </a>
                                        @endif
                                    </td>
                                    <td class="py-3.5 px-4 text-muted-foreground whitespace-nowrap">
                                        {{ ($item->requested_at ?? $item->created_at)?->format('d M Y, h:i A') }}
                                    </td>
                                    <td class="py-3.5 px-4 whitespace-nowrap">
                                        @if($item->status === 'pending')
                                            <x-ui.badge variant="warning" class="text-[10px]">
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1 animate-pulse"></span>
                                                {{ __('Pending Review') }}
                                            </x-ui.badge>
                                        @elseif($item->status === 'approved')
                                            <x-ui.badge variant="success" class="text-[10px]">
                                                {{ __('Approved') }}
                                            </x-ui.badge>
                                        @elseif($item->status === 'rejected')
                                            <x-ui.badge variant="destructive" class="text-[10px]">
                                                {{ __('Rejected') }}
                                            </x-ui.badge>
                                        @else
                                            <x-ui.badge variant="secondary" class="text-[10px]">
                                                {{ ucfirst($item->status) }}
                                            </x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="py-3.5 px-4 max-w-xs text-[11px]">
                                        @if($item->admin_notes)
                                            <div class="p-2 rounded bg-muted/50 border border-border/40 text-foreground">
                                                {{ $item->admin_notes }}
                                            </div>
                                        @else
                                            <span class="text-muted-foreground">—</span>
                                        @endif
                                        @if($item->effective_date)
                                            <div class="text-[10px] text-muted-foreground mt-0.5">
                                                {{ __('Effective:') }} {{ $item->effective_date->format('d M Y') }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                        @if($item->isPending())
                                            <x-ui.button size="xs" variant="outline" wire:click="cancelWithdrawalRequest({{ $item->id }})" class="text-rose-600 border-rose-300 hover:bg-rose-50 dark:hover:bg-rose-950/30">
                                                {{ __('Cancel') }}
                                            </x-ui.button>
                                        @else
                                            <span class="text-[10px] text-muted-foreground">{{ __('Archived') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    <!-- COURSE WITHDRAWAL / DISCONTINUATION MODAL (ENHANCED UI/UX) -->
    <x-ui.modal
        name="course-withdrawal-modal"
        max-width="max-w-xl"
        :title="__('Request Course Withdrawal or Discontinuation')"
        :description="__('Submit a formal request to withdraw or discontinue from your enrolled course.')"
    >
        @if($withdrawalEnrollment)
            <form wire:submit="submitWithdrawalRequest" class="space-y-4">
                <!-- Course & Enrollment Identifier Card -->
                <div class="p-3.5 bg-muted/40 rounded-xl border border-border space-y-1.5">
                    <div class="flex items-center justify-between gap-2 flex-wrap">
                        <span class="font-bold text-sm text-foreground">
                            {{ $withdrawalEnrollment->course?->name }} ({{ $withdrawalEnrollment->course?->code }})
                        </span>
                        <div class="flex items-center gap-1.5 flex-wrap">
                            @if(!empty($this->student->urn))
                                <x-ui.badge variant="outline" class="font-mono text-[11px] gap-1" title="{{ __('Universal Registration Number') }}">
                                    <span class="text-muted-foreground">{{ __('URN:') }}</span>
                                    <span class="font-bold text-foreground">{{ $this->student->urn }}</span>
                                </x-ui.badge>
                            @endif
                            <x-ui.badge variant="secondary" class="font-mono text-[11px]">
                                {{ __('Student ID: ') }}<strong>{{ $withdrawalEnrollment->student_id ?: $this->student->student_id }}</strong>
                            </x-ui.badge>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-muted-foreground flex-wrap">
                        <span>{{ __('Batch:') }} <strong>{{ $withdrawalEnrollment->batch?->name }}</strong></span>
                        <span>&bull;</span>
                        <span>{{ __('Enrollment No:') }} <strong class="font-mono text-primary">{{ $withdrawalEnrollment->enrollment_no }}</strong></span>
                        @if($withdrawalEnrollment->section)
                            <span>&bull;</span>
                            <span>{{ __('Section:') }} <strong>{{ $withdrawalEnrollment->section->name }}</strong></span>
                        @endif
                    </div>
                </div>

                <!-- Request Nature Selection: Interactive Choice Cards -->
                <div class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground block">
                        {{ __('Choose Request Nature *') }}
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <button
                            type="button"
                            wire:click="$set('withdrawalForm.request_type', 'withdrawal')"
                            class="p-3 rounded-lg border text-left transition-all cursor-pointer {{ $withdrawalForm['request_type'] === 'withdrawal' ? 'border-destructive bg-destructive/5 ring-1 ring-destructive' : 'border-border bg-card hover:bg-muted/40' }}"
                        >
                            <div class="flex items-center justify-between font-semibold text-xs text-foreground">
                                <span>{{ __('Voluntary Withdrawal') }}</span>
                                <x-icon name="{{ $withdrawalForm['request_type'] === 'withdrawal' ? 'check-circle' : 'circle' }}" class="w-4 h-4 {{ $withdrawalForm['request_type'] === 'withdrawal' ? 'text-destructive' : 'text-muted-foreground' }}" />
                            </div>
                            <p class="text-[11px] text-muted-foreground mt-1 leading-snug">
                                {{ __('Completely exit the course programme and permanently release your seat.') }}
                            </p>
                        </button>

                        <button
                            type="button"
                            wire:click="$set('withdrawalForm.request_type', 'discontinuation')"
                            class="p-3 rounded-lg border text-left transition-all cursor-pointer {{ $withdrawalForm['request_type'] === 'discontinuation' ? 'border-amber-500 bg-amber-500/5 ring-1 ring-amber-500' : 'border-border bg-card hover:bg-muted/40' }}"
                        >
                            <div class="flex items-center justify-between font-semibold text-xs text-foreground">
                                <span>{{ __('Academic Discontinuation') }}</span>
                                <x-icon name="{{ $withdrawalForm['request_type'] === 'discontinuation' ? 'check-circle' : 'circle' }}" class="w-4 h-4 {{ $withdrawalForm['request_type'] === 'discontinuation' ? 'text-amber-500' : 'text-muted-foreground' }}" />
                            </div>
                            <p class="text-[11px] text-muted-foreground mt-1 leading-snug">
                                {{ __('Discontinue studies due to medical conditions, employment appointment, or outstation transfer.') }}
                            </p>
                        </button>
                    </div>
                    @error('withdrawalForm.request_type')
                        <p class="text-xs text-destructive mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Primary Reason Category -->
                <div class="space-y-1">
                    <label class="text-xs font-semibold text-foreground block">{{ __('Primary Reason Category *') }}</label>
                    <select
                        wire:model="withdrawalForm.reason_category"
                        class="h-9 w-full rounded-md border border-input bg-card text-foreground px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        required
                    >
                        <option value="health_medical">{{ __('Medical / Health Concerns') }}</option>
                        <option value="employment_selection">{{ __('Selected in Employment / Job Joining') }}</option>
                        <option value="upsc_selection">{{ __('Selected in UPSC / Other Exam') }}</option>
                        <option value="relocation">{{ __('Family Relocation / Outstation Movement') }}</option>
                        <option value="financial">{{ __('Financial Constraints / Circumstances') }}</option>
                        <option value="personal">{{ __('Personal / Family Grounds') }}</option>
                        <option value="academic_difficulty">{{ __('Schedule Conflict / Academic Load') }}</option>
                        <option value="other">{{ __('Other Specific Grounds') }}</option>
                    </select>
                    @error('withdrawalForm.reason_category')
                        <p class="text-xs text-destructive mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Detailed Narrative Justification -->
                <div class="space-y-1">
                    <x-ui.textarea
                        wire:model="withdrawalForm.reason_description"
                        label="{{ __('Detailed Justification / Statement *') }}"
                        placeholder="{{ __('Please describe why you wish to withdraw or discontinue from this course...') }}"
                        rows="3"
                        :error="$errors->first('withdrawalForm.reason_description')"
                        required
                    />
                    <div class="flex justify-between text-[10px] text-muted-foreground pt-0.5">
                        <span>{{ __('Minimum 10 characters required.') }}</span>
                        <span>{{ strlen($withdrawalForm['reason_description'] ?? '') }}/1500</span>
                    </div>
                </div>

                <!-- Supporting Document Upload with Progress Indicator -->
                <div class="space-y-1.5 p-3 rounded-lg border border-border/80 bg-muted/20">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-foreground">
                            {{ __('Supporting Document (Optional)') }}
                        </label>
                        <span class="text-[10px] text-muted-foreground">{{ __('PDF, JPG, PNG up to 10MB') }}</span>
                    </div>

                    <input
                        type="file"
                        wire:model="withdrawalDocument"
                        accept=".pdf,.png,.jpg,.jpeg"
                        class="h-9 w-full rounded-md border border-input bg-card text-foreground px-3 text-xs file:mr-2 file:border-0 file:bg-transparent file:text-xs file:font-semibold"
                    />

                    <!-- Upload State -->
                    <div wire:loading wire:target="withdrawalDocument" class="text-xs text-primary font-medium flex items-center gap-2 pt-1">
                        <x-icon name="loader-2" class="w-4 h-4 animate-spin text-primary shrink-0" />
                        <span>{{ __('Uploading document proof, please wait...') }}</span>
                    </div>

                    @if($withdrawalDocument)
                        <div class="flex items-center justify-between text-xs text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 p-2 rounded border border-emerald-500/20 mt-1">
                            <span class="flex items-center gap-1.5 truncate">
                                <x-icon name="check-circle" class="w-4 h-4 shrink-0" />
                                <span class="truncate font-medium">{{ $withdrawalDocument->getClientOriginalName() }}</span>
                            </span>
                            <button type="button" wire:click="clearWithdrawalDocument" class="text-rose-600 hover:text-rose-700 text-xs font-bold shrink-0 ml-2">
                                {{ __('Remove') }}
                            </button>
                        </div>
                    @endif

                    @error('withdrawalDocument')
                        <p class="text-xs text-destructive mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Advisory Notice -->
                <div class="p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/40 rounded-lg text-xs text-amber-800 dark:text-amber-300 flex items-start gap-2">
                    <x-icon name="alert-triangle" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                    <span>{{ __('Once approved by administration, your course enrollment will be marked as Withdrawn/Discontinued and your seat allocation released.') }}</span>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" wire:click="closeWithdrawalModal">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" variant="destructive" wire:loading.attr="disabled" class="gap-1.5">
                        <span wire:loading.remove wire:target="submitWithdrawalRequest">{{ __('Submit Request') }}</span>
                        <span wire:loading wire:target="submitWithdrawalRequest" class="inline-flex items-center gap-1.5">
                            <x-icon name="loader-2" class="w-3.5 h-3.5 animate-spin" />
                            {{ __('Submitting...') }}
                        </span>
                    </x-ui.button>
                </div>
            </form>
        @else
            <div class="p-6 text-center text-xs text-muted-foreground">
                <x-icon name="loader-2" class="w-6 h-6 animate-spin text-primary mx-auto mb-2" />
                <p>{{ __('Loading enrollment details...') }}</p>
            </div>
        @endif
    </x-ui.modal>
</div>
