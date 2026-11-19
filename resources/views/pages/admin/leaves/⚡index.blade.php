<?php

use App\Models\AuditLog;
use App\Models\LeaveApplication;
use App\Models\LeaveCategory;
use App\Models\User;
use App\Services\Leave\LeaveManagementService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Leave Management Desk & Approvals')] class extends Component {
    use WithPagination;

    #[Url(as: 'tab')]
    public string $activeTab = 'pending'; // 'pending', 'all', 'staff', 'student'

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'cat')]
    public string $categoryFilter = '';

    public int $perPage = 15;

    // Action Modal State (Approve / Reject)
    public bool $showActionModal = false;
    public ?int $actionApplicationId = null;
    public string $actionType = 'approve'; // 'approve' or 'reject'
    public string $actionRemarks = '';

    // View Details Modal
    public ?LeaveApplication $viewingApplication = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatingActiveTab(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function categories()
    {
        return LeaveCategory::orderBy('name')->get();
    }

    #[Computed]
    public function stats(): array
    {
        $today = Carbon::today()->toDateString();

        return [
            'pending_count' => LeaveApplication::where('status', LeaveApplication::STATUS_PENDING)->count(),
            'approved_month' => LeaveApplication::where('status', LeaveApplication::STATUS_APPROVED)
                ->whereMonth('from_date', Carbon::now()->month)
                ->whereYear('from_date', Carbon::now()->year)
                ->count(),
            'active_today' => LeaveApplication::where('status', LeaveApplication::STATUS_APPROVED)
                ->where('from_date', '<=', $today)
                ->where('to_date', '>=', $today)
                ->count(),
            'rejected_count' => LeaveApplication::where('status', LeaveApplication::STATUS_REJECTED)->count(),
        ];
    }

    #[Computed]
    public function applications()
    {
        return LeaveApplication::query()
            ->when($this->activeTab === 'pending', fn (Builder $q) => $q->where('status', LeaveApplication::STATUS_PENDING))
            ->when($this->activeTab === 'staff', fn (Builder $q) => $q->where('user_type', 'staff'))
            ->when($this->activeTab === 'student', fn (Builder $q) => $q->where('user_type', 'student'))
            ->when($this->categoryFilter, fn (Builder $q) => $q->where('leave_category_id', $this->categoryFilter))
            ->when($this->search, function (Builder $q) {
                $q->whereHas('user', function ($uq) {
                    $uq->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%")
                        ->orWhere('student_id', 'like', "%{$this->search}%")
                        ->orWhere('employee_id', 'like', "%{$this->search}%");
                })->orWhere('application_no', 'like', "%{$this->search}%");
            })
            ->with(['user', 'category', 'approver'])
            ->latest('id')
            ->paginate($this->perPage);
    }

    public function openApproveModal(int $id): void
    {
        $this->actionApplicationId = $id;
        $this->actionType = 'approve';
        $this->actionRemarks = 'Leave approved. Attendance automatically synced.';
        $this->dispatch('modal-open', name: 'leave-action-modal');
    }

    public function openRejectModal(int $id): void
    {
        $this->actionApplicationId = $id;
        $this->actionType = 'reject';
        $this->actionRemarks = '';
        $this->dispatch('modal-open', name: 'leave-action-modal');
    }

    public function submitAction(LeaveManagementService $leaveService): void
    {
        if (! $this->actionApplicationId) {
            return;
        }

        try {
            $application = LeaveApplication::findOrFail($this->actionApplicationId);

            if ($this->actionType === 'approve') {
                $leaveService->approveLeave($application, auth()->user(), $this->actionRemarks);
                Toast::dispatch($this, 'success', __('Leave application :no approved and attendance synced.', ['no' => $application->application_no]));
            } else {
                $this->validate([
                    'actionRemarks' => 'required|string|min:3',
                ]);
                $leaveService->rejectLeave($application, auth()->user(), $this->actionRemarks);
                Toast::dispatch($this, 'success', __('Leave application :no rejected.', ['no' => $application->application_no]));
            }

            $this->dispatch('modal-close', name: 'leave-action-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function viewApplication(int $id): void
    {
        $this->viewingApplication = LeaveApplication::with(['user', 'category', 'approver', 'attendances'])->findOrFail($id);
        $this->dispatch('modal-open', name: 'leave-details-modal');
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Leave Management Desk') }}</h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                    {{ __('Staff & Students') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Review, approve, and track leave applications. Approved leaves automatically reflect as "On Leave" in the attendance register.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            @can('leaves.categories.manage')
                <a href="{{ route('admin.leaves.categories') }}" wire:navigate>
                    <x-ui.button variant="outline" size="sm" class="cursor-pointer">
                        <x-icon name="sliders" class="w-4 h-4 mr-1.5" />
                        {{ __('Leave Categories & Quotas') }}
                    </x-ui.button>
                </a>
            @endcan

            <a href="{{ route('admin.attendance.index') }}" wire:navigate>
                <x-ui.button variant="default" size="sm" class="cursor-pointer bg-primary text-primary-foreground font-semibold">
                    <x-icon name="calendar-check" class="w-4 h-4 mr-1.5" />
                    {{ __('Attendance Desk') }}
                </x-ui.button>
            </a>
        </div>
    </div>

    <!-- KPI Summary Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Pending Approvals -->
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Pending Approvals') }}</span>
                <span class="p-2 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                    <x-icon name="clock" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-2xl font-bold font-mono text-amber-600 dark:text-amber-400">{{ $this->stats['pending_count'] }}</span>
                <span class="text-xs text-muted-foreground">{{ __('requests awaiting action') }}</span>
            </div>
        </div>

        <!-- Active Leaves Today -->
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Active Leaves Today') }}</span>
                <span class="p-2 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400">
                    <x-icon name="calendar" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-2xl font-bold font-mono text-sky-600 dark:text-sky-400">{{ $this->stats['active_today'] }}</span>
                <span class="text-xs text-muted-foreground">{{ __('synced in attendance') }}</span>
            </div>
        </div>

        <!-- Approved This Month -->
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Approved This Month') }}</span>
                <span class="p-2 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="check-circle" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-2xl font-bold font-mono text-emerald-600 dark:text-emerald-400">{{ $this->stats['approved_month'] }}</span>
                <span class="text-xs text-muted-foreground">{{ __('approved leaves') }}</span>
            </div>
        </div>

        <!-- Rejected Applications -->
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted-foreground uppercase tracking-wider">{{ __('Rejected') }}</span>
                <span class="p-2 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400">
                    <x-icon name="x-circle" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-2xl font-bold font-mono text-rose-600 dark:text-rose-400">{{ $this->stats['rejected_count'] }}</span>
                <span class="text-xs text-muted-foreground">{{ __('disapproved') }}</span>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs & Filters -->
    <div class="p-4 rounded-xl border border-border bg-card shadow-xs space-y-4">
        <!-- Tabs -->
        <div class="flex flex-wrap items-center gap-2 pb-3 border-b border-border">
            <button
                type="button"
                wire:click="$set('activeTab', 'pending')"
                class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer flex items-center gap-2 {{ $activeTab === 'pending' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
            >
                <x-icon name="inbox" class="w-3.5 h-3.5" />
                {{ __('Pending Approvals') }}
                @if($this->stats['pending_count'] > 0)
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-amber-500 text-white">
                        {{ $this->stats['pending_count'] }}
                    </span>
                @endif
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'all')"
                class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer flex items-center gap-2 {{ $activeTab === 'all' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
            >
                {{ __('All Applications') }}
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'staff')"
                class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer flex items-center gap-2 {{ $activeTab === 'staff' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
            >
                <x-icon name="briefcase" class="w-3.5 h-3.5" />
                {{ __('Staff Leaves') }}
            </button>

            <button
                type="button"
                wire:click="$set('activeTab', 'student')"
                class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors cursor-pointer flex items-center gap-2 {{ $activeTab === 'student' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
            >
                <x-icon name="graduation-cap" class="w-3.5 h-3.5" />
                {{ __('Student Leaves') }}
            </button>
        </div>

        <!-- Filter Controls -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Category') }}</label>
                <select wire:model.live="categoryFilter" class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg">
                    <option value="">{{ __('All Categories') }}</option>
                    @foreach($this->categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Search Applicant') }}</label>
                <div class="relative">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Name, Application No, Email, ID...') }}"
                        class="w-full pl-8 pr-3 py-1.5 text-xs bg-background border border-border rounded-lg"
                    />
                    <x-icon name="search" class="w-3.5 h-3.5 text-muted-foreground absolute left-2.5 top-2.5" />
                </div>
            </div>
        </div>
    </div>

    <!-- Applications Table -->
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="px-4 py-3 border-b border-border flex items-center justify-between">
            <h2 class="text-sm font-semibold text-foreground">
                {{ __('Leave Applications Register') }}
            </h2>
            <span class="text-xs text-muted-foreground">
                {{ __('Total :count applications', ['count' => $this->applications->total()]) }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs divide-y divide-border">
                <thead class="bg-muted/50 text-muted-foreground font-medium uppercase text-[11px] tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('Application No') }}</th>
                        <th class="px-4 py-3">{{ __('Applicant & Role') }}</th>
                        <th class="px-4 py-3">{{ __('Category') }}</th>
                        <th class="px-4 py-3">{{ __('Leave Duration') }}</th>
                        <th class="px-4 py-3">{{ __('Days') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Attendance Sync') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    @forelse($this->applications as $app)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-4 py-3 font-mono font-medium text-foreground">
                                <button type="button" wire:click="viewApplication({{ $app->id }})" class="hover:underline text-primary text-left">
                                    {{ $app->application_no }}
                                </button>
                                <span class="text-[10px] text-muted-foreground block font-sans">
                                    {{ $app->created_at ? $app->created_at->format('d M, Y') : '' }}
                                </span>
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="w-7 h-7 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-xs uppercase">
                                        {{ substr($app->user?->name ?? 'U', 0, 2) }}
                                    </div>
                                    <div>
                                        <strong class="font-medium text-foreground block">{{ $app->user?->name }}</strong>
                                        <span class="text-[10px] text-muted-foreground font-mono">
                                            {{ $app->user?->student_id ?? $app->user?->employee_id ?? $app->user?->email }}
                                        </span>
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border {{ $app->category?->badgeColorClass() }}">
                                    {{ $app->category?->name }}
                                </span>
                            </td>

                            <td class="px-4 py-3 font-mono">
                                <span class="text-foreground block font-medium">
                                    {{ $app->from_date ? $app->from_date->format('d M, Y') : '' }}
                                </span>
                                <span class="text-muted-foreground text-[10px]">
                                    to {{ $app->to_date ? $app->to_date->format('d M, Y') : '' }}
                                </span>
                            </td>

                            <td class="px-4 py-3 font-mono font-bold text-foreground">
                                {{ $app->total_days }} {{ __('days') }}
                                @if($app->is_half_day)
                                    <span class="text-[9px] text-purple-600 dark:text-purple-400 block font-sans uppercase">
                                        ({{ str_replace('_', ' ', $app->half_day_type) }})
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border {{ $app->statusBadgeClass() }}">
                                    {{ ucfirst($app->status) }}
                                </span>
                                @if($app->action_remarks)
                                    <span class="text-[10px] text-muted-foreground block mt-0.5 max-w-xs truncate" title="{{ $app->action_remarks }}">
                                        {{ $app->action_remarks }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                @if($app->is_synced_to_attendance)
                                    <span class="inline-flex items-center gap-1 text-[11px] text-emerald-600 dark:text-emerald-400 font-semibold">
                                        <x-icon name="check-check" class="w-3.5 h-3.5" />
                                        {{ __('Synced') }}
                                    </span>
                                @else
                                    <span class="text-muted-foreground text-[11px]">-</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button
                                        type="button"
                                        wire:click="viewApplication({{ $app->id }})"
                                        class="p-1 text-muted-foreground hover:text-foreground cursor-pointer rounded hover:bg-muted"
                                        title="{{ __('View Details') }}"
                                    >
                                        <x-icon name="eye" class="w-4 h-4" />
                                    </button>

                                    @if($app->status === 'pending')
                                        @can('leaves.manage')
                                            <button
                                                type="button"
                                                wire:click="openApproveModal({{ $app->id }})"
                                                class="px-2 py-1 rounded bg-emerald-600 text-white font-medium text-xs hover:bg-emerald-700 cursor-pointer shadow-xs"
                                                title="{{ __('Approve Leave') }}"
                                            >
                                                {{ __('Approve') }}
                                            </button>

                                            <button
                                                type="button"
                                                wire:click="openRejectModal({{ $app->id }})"
                                                class="px-2 py-1 rounded bg-rose-600 text-white font-medium text-xs hover:bg-rose-700 cursor-pointer shadow-xs"
                                                title="{{ __('Reject Leave') }}"
                                            >
                                                {{ __('Reject') }}
                                            </button>
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                                <x-icon name="calendar-x" class="w-10 h-10 mx-auto text-muted-foreground/40 mb-2" />
                                <p class="text-sm font-medium">{{ __('No leave applications found.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->applications->hasPages())
            <div class="px-4 py-3 border-t border-border">
                {{ $this->applications->links() }}
            </div>
        @endif
    </div>

    <!-- Approve / Reject Modal -->
    <x-ui.modal name="leave-action-modal" :title="$actionType === 'approve' ? __('Approve Leave Request') : __('Disapprove Leave Request')">
        <form wire:submit="submitAction" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">
                    {{ $actionType === 'approve' ? __('Approval Remarks / Instructions') : __('Rejection Reason (Mandatory)') }} *
                </label>
                <textarea
                    wire:model="actionRemarks"
                    rows="3"
                    class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg focus:ring-1 focus:ring-primary"
                    placeholder="{{ $actionType === 'approve' ? __('Approved as requested.') : __('Reason for declining the leave application...') }}"
                ></textarea>
                @error('actionRemarks') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
            </div>

            @if($actionType === 'approve')
                <div class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-xs text-emerald-800 dark:text-emerald-300 flex items-start gap-2">
                    <x-icon name="info" class="w-4 h-4 shrink-0 mt-0.5" />
                    <span>{{ __('Approving this application will automatically update the candidate\'s attendance register for the requested date duration with status "On Leave".') }}</span>
                </div>
            @endif

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'leave-action-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="{{ $actionType === 'approve' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700' }} text-white font-semibold"
                >
                    {{ $actionType === 'approve' ? __('Confirm & Approve') : __('Confirm Rejection') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Details View Modal -->
    <x-ui.modal name="leave-details-modal" :title="__('Leave Application Dossier')" maxWidth="lg">
        @if($viewingApplication)
            <div class="space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <div>
                        <span class="text-xs text-muted-foreground block font-mono">{{ $viewingApplication->application_no }}</span>
                        <h3 class="text-base font-bold text-foreground">{{ $viewingApplication->user?->name }}</h3>
                        <span class="text-xs text-muted-foreground">{{ ucfirst($viewingApplication->user_type) }} | {{ $viewingApplication->user?->email }}</span>
                    </div>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider border {{ $viewingApplication->statusBadgeClass() }}">
                        {{ ucfirst($viewingApplication->status) }}
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div>
                        <span class="text-[10px] text-muted-foreground block">{{ __('Category') }}</span>
                        <strong class="text-foreground">{{ $viewingApplication->category?->name }}</strong>
                    </div>
                    <div>
                        <span class="text-[10px] text-muted-foreground block">{{ __('Total Duration') }}</span>
                        <strong class="text-foreground font-mono">{{ $viewingApplication->total_days }} days ({{ $viewingApplication->from_date->format('d M, Y') }} - {{ $viewingApplication->to_date->format('d M, Y') }})</strong>
                    </div>
                    @if($viewingApplication->emergency_contact)
                        <div>
                            <span class="text-[10px] text-muted-foreground block">{{ __('Emergency Contact') }}</span>
                            <span class="font-mono text-foreground">{{ $viewingApplication->emergency_contact }}</span>
                        </div>
                    @endif
                    @if($viewingApplication->attachment_path)
                        <div>
                            <span class="text-[10px] text-muted-foreground block">{{ __('Attachment / Document') }}</span>
                            <a href="{{ Storage::url($viewingApplication->attachment_path) }}" target="_blank" class="text-primary hover:underline font-medium flex items-center gap-1">
                                <x-icon name="file-text" class="w-3.5 h-3.5" />
                                {{ __('View Supporting Document') }}
                            </a>
                        </div>
                    @endif
                </div>

                <div>
                    <span class="text-[10px] text-muted-foreground block">{{ __('Purpose of Leave / Reason') }}</span>
                    <p class="text-xs text-foreground mt-1 p-3 rounded-lg bg-muted/40 border border-border/40 whitespace-pre-wrap">
                        {{ $viewingApplication->reason }}
                    </p>
                </div>

                @if($viewingApplication->action_by)
                    <div class="p-3 rounded-lg bg-muted/30 border border-border/60 text-xs">
                        <span class="text-[10px] text-muted-foreground block">
                            {{ $viewingApplication->status === 'approved' ? __('Approved By') : __('Actioned By') }}:
                            <strong>{{ $viewingApplication->approver?->name }}</strong> on {{ $viewingApplication->action_at ? $viewingApplication->action_at->format('d M, Y H:i') : '' }}
                        </span>
                        @if($viewingApplication->action_remarks)
                            <p class="mt-1 text-foreground italic">"{{ $viewingApplication->action_remarks }}"</p>
                        @endif
                    </div>
                @endif

                <div class="pt-4 border-t border-border flex justify-end">
                    <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'leave-details-modal' })">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
