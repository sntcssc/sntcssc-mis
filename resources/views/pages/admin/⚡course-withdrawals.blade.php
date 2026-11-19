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
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Course Withdrawal Requests')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'pending';

    #[Url(as: 'course')]
    public string $courseFilter = '';

    #[Url(as: 'batch')]
    public string $batchFilter = '';

    public int $perPage = 10;

    // Review Modal State
    public ?int $reviewingRequestId = null;
    public ?CourseWithdrawalRequest $reviewingRequest = null;
    public string $adminNotes = '';
    public string $effectiveDate = '';
    public array $reviewForm = [
        'status' => 'approved',
        'target_enrollment_status' => 'withdrawn',
        'effective_date' => '',
        'admin_notes' => '',
    ];

    // Revert Modal State
    public ?int $revertingRequestId = null;
    public ?CourseWithdrawalRequest $revertingRequest = null;
    public array $revertForm = [
        'order_number' => '',
        'revert_reason' => '',
        'activate_user' => true,
    ];

    public function mount(): void
    {
        $this->effectiveDate = now()->format('Y-m-d');
        $this->reviewForm['effective_date'] = $this->effectiveDate;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingCourseFilter(): void
    {
        $this->resetPage();
    }

    public function updatingBatchFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'pending' => CourseWithdrawalRequest::where('status', 'pending')->count(),
            'approved' => CourseWithdrawalRequest::where('status', 'approved')->count(),
            'rejected' => CourseWithdrawalRequest::where('status', 'rejected')->count(),
            'reverted' => CourseWithdrawalRequest::where('status', 'reverted')->count(),
            'total' => CourseWithdrawalRequest::count(),
        ];
    }

    #[Computed]
    public function courses()
    {
        return Course::orderBy('name')->get();
    }

    #[Computed]
    public function batches()
    {
        return Batch::orderByDesc('academic_year')->get();
    }

    #[Computed]
    public function requests()
    {
        return CourseWithdrawalRequest::query()
            ->with(['student.studentProfile', 'enrollment.section', 'course', 'batch', 'reviewer'])
            ->when($this->statusFilter && $this->statusFilter !== 'all', function (Builder $q) {
                $q->where('status', $this->statusFilter);
            })
            ->when($this->courseFilter, function (Builder $q) {
                $q->where('course_id', (int) $this->courseFilter);
            })
            ->when($this->batchFilter, function (Builder $q) {
                $q->where('batch_id', (int) $this->batchFilter);
            })
            ->when($this->search, function (Builder $q) {
                $term = '%'.trim($this->search).'%';
                $q->where(function (Builder $sub) use ($term) {
                    $sub->where('request_no', 'like', $term)
                        ->orWhere('reason', 'like', $term)
                        ->orWhere('reason_details', 'like', $term)
                        ->orWhereHas('student', function (Builder $sq) use ($term) {
                            $sq->where('name', 'like', $term)
                                ->orWhere('email', 'like', $term)
                                ->orWhere('student_id', 'like', $term)
                                ->orWhere('phone', 'like', $term);
                        })
                        ->orWhereHas('course', function (Builder $cq) use ($term) {
                            $cq->where('name', 'like', $term)
                                ->orWhere('code', 'like', $term);
                        })
                        ->orWhereHas('enrollment', function (Builder $eq) use ($term) {
                            $eq->where('enrollment_no', 'like', $term)
                                ->orWhere('roll_no', 'like', $term);
                        });
                });
            })
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }

    public function openReviewModal(int $id): void
    {
        $this->reviewingRequestId = $id;
        $this->reviewingRequest = CourseWithdrawalRequest::with(['student.studentProfile', 'enrollment.section', 'course', 'batch', 'reviewer'])->findOrFail($id);
        $this->adminNotes = $this->reviewingRequest->admin_notes ?? '';
        $this->effectiveDate = $this->reviewingRequest->effective_date ? $this->reviewingRequest->effective_date->format('Y-m-d') : now()->format('Y-m-d');
        $this->reviewForm = [
            'status' => 'approved',
            'target_enrollment_status' => $this->reviewingRequest->type === 'discontinuation' ? 'discontinued' : 'withdrawn',
            'effective_date' => $this->effectiveDate,
            'admin_notes' => $this->adminNotes,
        ];

        $this->dispatch('modal-open', name: 'review-withdrawal-modal');
    }

    public function closeReviewModal(): void
    {
        $this->reviewingRequestId = null;
        $this->reviewingRequest = null;
        $this->adminNotes = '';
        $this->dispatch('modal-close', name: 'review-withdrawal-modal');
    }

    public function processReview(): void
    {
        if (($this->reviewForm['status'] ?? 'approved') === 'rejected') {
            $this->rejectRequest();
        } else {
            $this->approveRequest();
        }
    }

    public function approveRequest(): void
    {
        if (! auth()->user()?->can('withdrawals.review') && ! auth()->user()?->hasRole('Super Administrator')) {
            Toast::dispatch($this, 'error', __('Unauthorized: You lack permission to approve withdrawal requests.'));
            return;
        }

        if (! $this->reviewingRequestId) {
            return;
        }

        if (! empty($this->reviewForm['effective_date'])) {
            $this->effectiveDate = $this->reviewForm['effective_date'];
        }
        if (! empty($this->reviewForm['admin_notes'])) {
            $this->adminNotes = $this->reviewForm['admin_notes'];
        }

        $this->validate([
            'effectiveDate' => ['required', 'date'],
            'adminNotes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::beginTransaction();
        try {
            $req = CourseWithdrawalRequest::lockForUpdate()->findOrFail($this->reviewingRequestId);

            if ($req->status !== 'pending') {
                Toast::dispatch($this, 'error', __('This request has already been processed.'));
                DB::rollBack();
                $this->closeReviewModal();
                return;
            }

            $user = auth()->user();
            $enrollment = $req->enrollment;

            $statusToSet = $this->reviewForm['target_enrollment_status'] ?? ($req->type === 'discontinuation' ? 'discontinued' : 'withdrawn');

            // 1. Update enrollment
            if ($enrollment) {
                $enrollment->update([
                    'status' => $statusToSet,
                    'discontinued_at' => $this->effectiveDate,
                    'discontinued_by' => $user->id,
                    'cancellation_reason' => 'Approved Course Withdrawal: '.($this->adminNotes ?: $req->reason),
                ]);
            }

            // 2. Update request
            $req->update([
                'status' => 'approved',
                'effective_date' => $this->effectiveDate,
                'admin_notes' => $this->adminNotes,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            // 3. Audit Log
            AuditLog::record(
                action: 'course_withdrawal.reviewed',
                details: [
                    'request_id' => $req->id,
                    'request_number' => $req->request_no,
                    'status' => 'approved',
                    'enrollment_status' => $statusToSet,
                    'admin_notes' => $this->adminNotes,
                ],
                userId: $user->id,
                auditableType: CourseWithdrawalRequest::class,
                auditableId: $req->id,
                description: "Approved course withdrawal request #{$req->request_no} for student {$req->student?->name} ({$req->student?->student_id}) from course {$req->course?->name}."
            );

            DB::commit();

            Toast::dispatch($this, 'success', __('Course withdrawal request # :no approved successfully.', ['no' => $req->request_no]));
            $this->closeReviewModal();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            Toast::dispatch($this, 'error', __('Failed to approve request: ').$e->getMessage());
        }
    }

    public function rejectRequest(): void
    {
        if (! auth()->user()?->can('withdrawals.review') && ! auth()->user()?->hasRole('Super Administrator')) {
            Toast::dispatch($this, 'error', __('Unauthorized: You lack permission to reject withdrawal requests.'));
            return;
        }

        if (! $this->reviewingRequestId) {
            return;
        }

        if (! empty($this->reviewForm['admin_notes'])) {
            $this->adminNotes = $this->reviewForm['admin_notes'];
        }

        $this->validate([
            'adminNotes' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'adminNotes.required' => __('Please specify the reason for rejecting this withdrawal request.'),
        ]);

        DB::beginTransaction();
        try {
            $req = CourseWithdrawalRequest::lockForUpdate()->findOrFail($this->reviewingRequestId);

            if ($req->status !== 'pending') {
                Toast::dispatch($this, 'error', __('This request has already been processed.'));
                DB::rollBack();
                $this->closeReviewModal();
                return;
            }

            $user = auth()->user();

            $req->update([
                'status' => 'rejected',
                'admin_notes' => $this->adminNotes,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            AuditLog::record(
                action: 'course_withdrawal.reviewed',
                details: [
                    'request_id' => $req->id,
                    'request_number' => $req->request_no,
                    'status' => 'rejected',
                    'admin_notes' => $this->adminNotes,
                ],
                userId: $user->id,
                auditableType: CourseWithdrawalRequest::class,
                auditableId: $req->id,
                description: "Rejected course withdrawal request #{$req->request_no} for student {$req->student?->name} ({$req->student?->student_id}) with note: {$this->adminNotes}"
            );

            DB::commit();

            Toast::dispatch($this, 'success', __('Course withdrawal request # :no rejected.', ['no' => $req->request_no]));
            $this->closeReviewModal();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            Toast::dispatch($this, 'error', __('Failed to reject request: ').$e->getMessage());
        }
    }

    public function openRevertModal(int $id): void
    {
        $this->revertingRequestId = $id;
        $this->revertingRequest = CourseWithdrawalRequest::with(['student.studentProfile', 'enrollment.section', 'course', 'batch'])->findOrFail($id);
        $this->revertForm = [
            'order_number' => 'REV-WTH-'.date('Y').'-'.rand(1000, 9999),
            'revert_reason' => '',
            'activate_user' => true,
        ];
        $this->dispatch('modal-open', name: 'revert-withdrawal-modal');
    }

    public function closeRevertModal(): void
    {
        $this->revertingRequestId = null;
        $this->revertingRequest = null;
        $this->revertForm = [
            'order_number' => '',
            'revert_reason' => '',
            'activate_user' => true,
        ];
        $this->dispatch('modal-close', name: 'revert-withdrawal-modal');
    }

    public function executeRevert(): void
    {
        if (! auth()->user()?->can('withdrawals.review') && ! auth()->user()?->can('enrollments.reinstate') && ! auth()->user()?->hasRole('Super Administrator')) {
            Toast::dispatch($this, 'error', __('Unauthorized: You lack permission to revert withdrawal requests.'));
            return;
        }

        $this->validate([
            'revertForm.revert_reason' => ['required', 'string', 'min:5', 'max:1000'],
            'revertForm.order_number' => ['nullable', 'string', 'max:100'],
        ]);

        if (! $this->revertingRequestId) {
            return;
        }

        DB::beginTransaction();
        try {
            $req = CourseWithdrawalRequest::with(['enrollment', 'student'])->lockForUpdate()->findOrFail($this->revertingRequestId);

            if ($req->status !== 'approved') {
                Toast::dispatch($this, 'error', __('Only approved withdrawal requests can be reverted.'));
                DB::rollBack();
                $this->closeRevertModal();
                return;
            }

            $user = auth()->user();
            $enrollment = $req->enrollment;

            // Reinstate enrollment if exists
            if ($enrollment) {
                $enrollment->reinstate(
                    reason: $this->revertForm['revert_reason'],
                    adminId: $user->id,
                    activateUser: (bool) ($this->revertForm['activate_user'] ?? true),
                    orderNumber: $this->revertForm['order_number'] ?: null
                );
            }

            // Update request
            $req->update([
                'status' => 'reverted',
                'admin_notes' => trim(($req->admin_notes ? $req->admin_notes."\n" : '').'['.now()->format('Y-m-d H:i')." Reverted by {$user->name}: ".trim($this->revertForm['revert_reason']).']'),
            ]);

            AuditLog::record(
                action: 'course_withdrawal.reverted',
                details: [
                    'request_id' => $req->id,
                    'request_number' => $req->request_no,
                    'status' => 'reverted',
                    'order_number' => $this->revertForm['order_number'] ?? null,
                    'reason' => $this->revertForm['revert_reason'],
                ],
                userId: $user->id,
                auditableType: CourseWithdrawalRequest::class,
                auditableId: $req->id,
                description: "Reverted approved course withdrawal request #{$req->request_no} for student {$req->student?->name} ({$req->student?->student_id}) and reinstated enrollment."
            );

            DB::commit();

            Toast::dispatch($this, 'success', __('Withdrawal request # :no reverted and student successfully reinstated.', ['no' => $req->request_no]));
            $this->closeRevertModal();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            Toast::dispatch($this, 'error', __('Failed to revert request: ').$e->getMessage());
        }
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-foreground flex items-center gap-2">
                <x-icon name="user-x" class="w-6 h-6 text-primary" />
                {{ __('Course Withdrawal & Discontinuation Requests') }}
            </h1>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1">
                {{ __('Review, approve, or reject student requests to withdraw or discontinue from academic courses.') }}
            </p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
        <div class="bg-card border border-border rounded-xl p-4 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground block">{{ __('Pending Review') }}</span>
            <div class="flex items-center gap-2 mt-1">
                <span class="text-2xl font-bold text-amber-500 font-mono">{{ $this->stats['pending'] }}</span>
                @if($this->stats['pending'] > 0)
                    <span class="h-2 w-2 rounded-full bg-amber-500 animate-ping"></span>
                @endif
            </div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Awaiting administrative decision') }}</p>
        </div>

        <div class="bg-card border border-border rounded-xl p-4 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground block">{{ __('Approved') }}</span>
            <div class="flex items-center gap-2 mt-1">
                <span class="text-2xl font-bold text-emerald-500 font-mono">{{ $this->stats['approved'] }}</span>
            </div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Courses discontinued / withdrawn') }}</p>
        </div>

        <div class="bg-card border border-border rounded-xl p-4 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground block">{{ __('Rejected') }}</span>
            <div class="flex items-center gap-2 mt-1">
                <span class="text-2xl font-bold text-rose-500 font-mono">{{ $this->stats['rejected'] }}</span>
            </div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Enrolments retained active') }}</p>
        </div>

        <div class="bg-card border border-border rounded-xl p-4 shadow-xs">
            <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground block">{{ __('Total Processed') }}</span>
            <div class="flex items-center gap-2 mt-1">
                <span class="text-2xl font-bold text-foreground font-mono">{{ $this->stats['total'] }}</span>
            </div>
            <p class="text-[11px] text-muted-foreground mt-0.5">{{ __('Lifetime request logs') }}</p>
        </div>
    </div>

    <!-- Filters & Search -->
    <div class="bg-card border border-border rounded-xl p-4 shadow-xs space-y-3">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <div class="flex flex-1 flex-col sm:flex-row items-stretch sm:items-center gap-2">
                <!-- Search input -->
                <div class="relative flex-1">
                    <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search request #, student name, ID, course, or reason...') }}"
                        class="w-full h-9 pl-9 pr-3 rounded-lg border border-input bg-background text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring"
                    />
                </div>

                <!-- Status filter pills -->
                <div class="flex items-center gap-1 overflow-x-auto pb-1 sm:pb-0">
                    @foreach(['pending' => __('Pending'), 'approved' => __('Approved'), 'rejected' => __('Rejected'), 'reverted' => __('Reverted'), 'all' => __('All')] as $val => $label)
                        <button
                            type="button"
                            wire:click="$set('statusFilter', '{{ $val }}')"
                            class="px-2.5 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition-colors cursor-pointer {{ $statusFilter === $val ? 'bg-primary text-primary-foreground' : 'bg-muted/50 text-muted-foreground hover:bg-muted hover:text-foreground' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- Course & Batch dropdowns -->
            <div class="flex items-center gap-2">
                <select wire:model.live="courseFilter" class="h-9 rounded-lg border border-input bg-background px-2.5 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                    <option value="">{{ __('All Courses') }}</option>
                    @foreach($this->courses as $c)
                        <option value="{{ $c->id }}">{{ $c->code }} - {{ $c->name }}</option>
                    @endforeach
                </select>

                <select wire:model.live="batchFilter" class="h-9 rounded-lg border border-input bg-background px-2.5 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                    <option value="">{{ __('All Batches') }}</option>
                    @foreach($this->batches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <div class="bg-card border border-border rounded-xl shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-muted/40 font-semibold text-muted-foreground">
                        <th class="py-3 px-4">{{ __('Request #') }}</th>
                        <th class="py-3 px-4">{{ __('Student / Candidate') }}</th>
                        <th class="py-3 px-4">{{ __('Course & Batch') }}</th>
                        <th class="py-3 px-4">{{ __('Reason Category') }}</th>
                        <th class="py-3 px-4">{{ __('Submitted') }}</th>
                        <th class="py-3 px-4">{{ __('Status') }}</th>
                        <th class="py-3 px-4 text-right">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    @forelse($this->requests as $req)
                        <tr class="hover:bg-muted/20 transition-colors">
                            <td class="py-3 px-4">
                                <span class="font-mono font-bold text-foreground block">{{ $req->request_no }}</span>
                                <span class="text-[10px] text-muted-foreground capitalize">{{ $req->type }}</span>
                            </td>
                            <td class="py-3 px-4">
                                <div class="font-medium text-foreground">{{ $req->student?->name ?? '—' }}</div>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <x-ui.badge variant="secondary" class="font-mono text-[10px] py-0">
                                        {{ $req->student?->student_id ?: 'ID: Pending' }}
                                    </x-ui.badge>
                                    <span class="text-[10px] text-muted-foreground">{{ $req->student?->email }}</span>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <div class="font-semibold text-foreground flex items-center gap-1.5">
                                    <span>{{ $req->course?->name ?? '—' }}</span>
                                    @if($req->course?->code)
                                        <x-ui.badge variant="outline" class="font-mono text-[9px] py-0">{{ $req->course->code }}</x-ui.badge>
                                    @endif
                                </div>
                                <div class="text-[10px] text-muted-foreground mt-0.5">
                                    {{ $req->batch?->name ?? '—' }}
                                    @if($req->enrollment?->section)
                                        · {{ $req->enrollment->section->name }}
                                    @endif
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <x-ui.badge variant="secondary" class="text-[10px]">
                                    {{ $req->getReasonLabel() }}
                                </x-ui.badge>
                                @if($req->reason_details)
                                    <p class="text-[11px] text-muted-foreground line-clamp-1 max-w-xs mt-0.5">
                                        {{ $req->reason_details }}
                                    </p>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-muted-foreground">
                                <div>{{ $req->created_at->format('d M Y') }}</div>
                                <div class="text-[10px]">{{ $req->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="py-3 px-4">
                                @if($req->status === 'pending')
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/15 text-amber-600 dark:text-amber-400">
                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                        {{ __('Pending') }}
                                    </span>
                                @elseif($req->status === 'approved')
                                    <x-ui.badge variant="success" class="text-[10px]">
                                        {{ __('Approved') }}
                                    </x-ui.badge>
                                @elseif($req->status === 'rejected')
                                    <x-ui.badge variant="destructive" class="text-[10px]">
                                        {{ __('Rejected') }}
                                    </x-ui.badge>
                                @elseif($req->status === 'reverted')
                                    <x-ui.badge variant="secondary" class="text-[10px] bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300 border border-purple-200 dark:border-purple-800">
                                        {{ __('Reverted') }}
                                    </x-ui.badge>
                                @else
                                    <x-ui.badge variant="secondary" class="text-[10px]">
                                        {{ __('Withdrawn') }}
                                    </x-ui.badge>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <x-ui.button
                                        size="sm"
                                        variant="outline"
                                        wire:click="openReviewModal({{ $req->id }})"
                                        class="text-xs gap-1"
                                    >
                                        <x-icon name="eye" class="w-3.5 h-3.5" />
                                        <span>{{ $req->status === 'pending' ? __('Review') : __('View') }}</span>
                                    </x-ui.button>

                                    @if($req->status === 'approved' && (auth()->user()?->can('enrollments.reinstate') || auth()->user()?->can('withdrawals.review') || auth()->user()?->hasRole('Super Administrator')))
                                        <button
                                            type="button"
                                            wire:click="openRevertModal({{ $req->id }})"
                                            class="inline-flex items-center gap-1 px-2 py-1 rounded bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-900/50 border border-emerald-200 dark:border-emerald-800 text-[11px] font-medium transition-colors cursor-pointer"
                                            title="{{ __('Revert Withdrawal & Reinstate Student to Course') }}"
                                        >
                                            <x-icon name="rotate-ccw" class="w-3.5 h-3.5" />
                                            <span>{{ __('Revert') }}</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-muted-foreground">
                                <x-icon name="check-circle" class="w-8 h-8 text-emerald-500 mx-auto mb-2 opacity-80" />
                                <p class="text-sm font-semibold">{{ __('No withdrawal requests found.') }}</p>
                                <p class="text-xs mt-0.5">{{ __('All student requests are up to date.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->requests->hasPages())
            <div class="p-3 border-t border-border">
                {{ $this->requests->links() }}
            </div>
        @endif
    </div>

    <!-- Review Modal -->
    <x-ui.modal name="review-withdrawal-modal" max-width="max-w-2xl" :title="__('Review Course Withdrawal Request')" :description="__('Evaluate student discontinuation appeal, record administrative notes, and update enrolment records.')">
        @if($reviewingRequest)
            <div class="space-y-4 text-xs">
                <!-- Request Header Highlight -->
                <div class="p-3.5 rounded-lg border border-border bg-muted/30 flex items-center justify-between">
                    <div>
                        <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Request Reference') }}</span>
                        <span class="text-sm font-mono font-bold text-foreground">{{ $reviewingRequest->request_no }}</span>
                    </div>
                    <div class="text-right">
                        <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Type') }}</span>
                        <span class="font-semibold text-foreground capitalize">{{ $reviewingRequest->type }}</span>
                    </div>
                </div>

                <!-- Student & Course Information Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="p-3 rounded-lg border border-border bg-card space-y-1.5">
                        <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Student Information') }}</span>
                        <div class="font-bold text-foreground text-sm">{{ $reviewingRequest->student?->name }}</div>
                        <div class="text-muted-foreground">
                            {{ __('Student ID: ') }}<strong class="font-mono text-foreground">{{ $reviewingRequest->student?->student_id ?: 'Pending' }}</strong>
                        </div>
                        <div class="text-muted-foreground">{{ $reviewingRequest->student?->email }}</div>
                        <div class="text-muted-foreground">{{ $reviewingRequest->student?->phone ?: 'No phone' }}</div>
                    </div>

                    <div class="p-3 rounded-lg border border-border bg-card space-y-1.5">
                        <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Course & Cohort') }}</span>
                        <div class="font-bold text-foreground text-sm">{{ $reviewingRequest->course?->name }}</div>
                        <div class="text-muted-foreground">
                            {{ __('Batch: ') }}<strong>{{ $reviewingRequest->batch?->name }}</strong>
                        </div>
                        <div class="text-muted-foreground">
                            {{ __('Enrolment No: ') }}<strong class="font-mono text-foreground">{{ $reviewingRequest->enrollment?->enrollment_no }}</strong>
                        </div>
                        <div class="text-muted-foreground">
                            {{ __('Section: ') }}<strong>{{ $reviewingRequest->enrollment?->section?->name ?? 'Unassigned' }}</strong>
                        </div>
                    </div>
                </div>

                <!-- Reason & Explanation -->
                <div class="p-3.5 rounded-lg border border-border bg-card space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] uppercase font-bold text-muted-foreground">{{ __('Stated Grounds / Reason') }}</span>
                        <x-ui.badge variant="secondary">
                            {{ $reviewingRequest->getReasonLabel() }}
                        </x-ui.badge>
                    </div>
                    <p class="text-xs text-foreground bg-muted/30 p-2.5 rounded border border-border/50 leading-relaxed whitespace-pre-wrap">
                        {{ $reviewingRequest->reason_details ?: __('No detailed remarks provided by student.') }}
                    </p>

                    @if($reviewingRequest->supporting_document)
                        <div class="pt-2 flex items-center justify-between text-xs">
                            <span class="font-semibold text-foreground flex items-center gap-1">
                                <x-icon name="paperclip" class="w-3.5 h-3.5 text-muted-foreground" />
                                {{ __('Attached Proof Document') }}
                            </span>
                            <a
                                href="{{ Storage::url($reviewingRequest->supporting_document) }}"
                                target="_blank"
                                class="inline-flex items-center gap-1 text-primary hover:underline font-semibold"
                            >
                                <x-icon name="external-link" class="w-3.5 h-3.5" />
                                {{ __('View Uploaded Document') }}
                            </a>
                        </div>
                    @endif
                </div>

                <!-- Prior Review Status (if not pending) -->
                @if($reviewingRequest->status !== 'pending')
                    <div class="p-3 rounded-lg border border-border bg-muted/40 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-foreground capitalize">{{ __('Status: ') }}{{ $reviewingRequest->status }}</span>
                            <span class="text-muted-foreground text-[10px]">
                                {{ __('Reviewed on ') }}{{ $reviewingRequest->reviewed_at?->format('d M Y, h:i A') }}
                            </span>
                        </div>
                        @if($reviewingRequest->reviewer)
                            <div class="text-muted-foreground">
                                {{ __('Reviewed by: ') }}<strong>{{ $reviewingRequest->reviewer->name }}</strong>
                            </div>
                        @endif
                        @if($reviewingRequest->admin_notes)
                            <div class="mt-1 text-foreground bg-card p-2 rounded border border-border/50">
                                <strong>{{ __('Admin Notes: ') }}</strong>{{ $reviewingRequest->admin_notes }}
                            </div>
                        @endif
                        @if($reviewingRequest->status === 'approved' && (auth()->user()?->can('enrollments.reinstate') || auth()->user()?->can('withdrawals.review') || auth()->user()?->hasRole('Super Administrator')))
                            <div class="pt-2 flex justify-end">
                                <x-ui.button
                                    type="button"
                                    size="xs"
                                    variant="default"
                                    class="bg-emerald-600 hover:bg-emerald-700 text-white gap-1.5 cursor-pointer"
                                    wire:click="openRevertModal({{ $reviewingRequest->id }})"
                                >
                                    <x-icon name="rotate-ccw" class="w-3.5 h-3.5" />
                                    {{ __('Revert Withdrawal & Reinstate Student') }}
                                </x-ui.button>
                            </div>
                        @endif
                    </div>
                @else
                    <!-- Review Form -->
                    <div class="space-y-3 pt-2 border-t border-border">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">
                                    {{ __('Effective Discontinuation Date') }} *
                                </label>
                                <input
                                    type="date"
                                    wire:model="effectiveDate"
                                    class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                    required
                                />
                                @error('effectiveDate') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-foreground block mb-1">
                                {{ __('Administrative Remarks / Decision Notes') }}
                            </label>
                            <textarea
                                wire:model="adminNotes"
                                rows="3"
                                placeholder="{{ __('Enter official findings, fee adjustment notes, or reasons for approval/rejection...') }}"
                                class="w-full rounded-md border border-input bg-card p-2.5 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            ></textarea>
                            @error('adminNotes') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>

                        <!-- Action Buttons -->
                        <div class="pt-3 flex flex-col-reverse sm:flex-row sm:items-center justify-between gap-2">
                            <x-ui.button type="button" variant="outline" wire:click="closeReviewModal">
                                {{ __('Cancel') }}
                            </x-ui.button>

                            <div class="flex items-center gap-2">
                                <x-ui.button
                                    type="button"
                                    variant="destructive"
                                    wire:click="rejectRequest"
                                    wire:confirm="{{ __('Are you sure you want to reject this course withdrawal request? The enrolment will remain active.') }}"
                                    class="gap-1.5"
                                >
                                    <x-icon name="x" class="w-4 h-4" />
                                    {{ __('Reject Request') }}
                                </x-ui.button>

                                <x-ui.button
                                    type="button"
                                    variant="primary"
                                    wire:click="approveRequest"
                                    wire:confirm="{{ __('Are you sure you want to approve this withdrawal? The student enrolment will be marked as withdrawn/discontinued.') }}"
                                    class="gap-1.5 bg-emerald-600 hover:bg-emerald-700"
                                >
                                    <x-icon name="check" class="w-4 h-4" />
                                    {{ __('Approve Withdrawal') }}
                                </x-ui.button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </x-ui.modal>

    <!-- Revert Withdrawal & Reinstate Student Modal -->
    <x-ui.modal
        name="revert-withdrawal-modal"
        max-width="max-w-lg"
        :title="__('Revert Course Withdrawal & Reinstate Student')"
        :description="__('Administratively revert an approved withdrawal and restore student enrolment to active status.')"
    >
        @if($revertingRequest)
            <form wire:submit="executeRevert" class="space-y-4">
                <div class="p-3 bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-lg text-xs space-y-1.5">
                    <div class="flex items-center justify-between font-bold text-emerald-900 dark:text-emerald-200">
                        <span>{{ $revertingRequest->student?->name }}</span>
                        <span class="font-mono text-emerald-700 dark:text-emerald-400">{{ $revertingRequest->request_no }}</span>
                    </div>
                    <div class="text-emerald-800 dark:text-emerald-300">
                        <strong>{{ __('Course:') }}</strong> {{ $revertingRequest->course?->name }} ({{ $revertingRequest->course?->code }})
                        &bull; <strong>{{ __('Batch:') }}</strong> {{ $revertingRequest->batch?->name }}
                    </div>
                    <div class="flex items-center gap-2 pt-1">
                        <span class="text-muted-foreground">{{ __('Enrolment #:') }}</span>
                        <span class="font-mono font-semibold text-foreground">{{ $revertingRequest->enrollment?->enrollment_no ?? 'N/A' }}</span>
                        <x-ui.badge variant="destructive" size="sm">
                            {{ ucfirst($revertingRequest->enrollment?->status ?? 'Withdrawn') }}
                        </x-ui.badge>
                    </div>
                </div>

                <div class="space-y-3">
                    <x-ui.input
                        wire:model="revertForm.order_number"
                        label="{{ __('Reinstatement Order / Decision Reference # (Optional)') }}"
                        placeholder="{{ __('e.g. REV-WTH-2026-008') }}"
                    />

                    <x-ui.textarea
                        wire:model="revertForm.revert_reason"
                        label="{{ __('Reason for Reversal & Reinstatement *') }}"
                        placeholder="{{ __('Specify the reason to revert withdrawal (e.g. appeal accepted, candidate joined back after leave, administrative error correction)...') }}"
                        rows="3"
                        required
                    />

                    <div class="p-2.5 bg-muted/40 rounded-lg border border-border">
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input
                                type="checkbox"
                                wire:model="revertForm.activate_user"
                                class="rounded border-input text-primary focus:ring-ring"
                            />
                            <span class="font-medium text-foreground">
                                {{ __('Also restore student account status to "Active" (if currently inactive or discontinued)') }}
                            </span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('revert-withdrawal-modal')">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" variant="default" class="bg-emerald-600 hover:bg-emerald-700 text-white cursor-pointer">
                        <x-icon name="rotate-ccw" class="w-4 h-4 mr-1.5" />
                        {{ __('Confirm Reversal & Reinstate') }}
                    </x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.modal>
</div>
