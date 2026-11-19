<?php

use App\Models\AuditLog;
use App\Models\LeaveApplication;
use App\Models\LeaveCategory;
use App\Services\FileUploadService;
use App\Services\Leave\LeaveManagementService;
use App\Support\Toast;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('My Leaves & Applications')] class extends Component {
    use WithFileUploads, WithPagination;

    // Apply Form State
    public int|string|null $categoryId = null;
    public string $fromDate = '';
    public string $toDate = '';
    public bool $isHalfDay = false;
    public string $halfDayType = 'first_half';
    public string $reason = '';
    public string $emergencyContact = '';
    public $attachment = null;

    public ?int $cancelApplicationId = null;

    public int $perPage = 10;

    public function updatedCategoryId(mixed $val): void
    {
        if ($val === '') {
            $this->categoryId = null;
        }
    }

    public function mount(): void
    {
        $this->fromDate = Carbon::tomorrow()->toDateString();
        $this->toDate = Carbon::tomorrow()->toDateString();
        $this->emergencyContact = auth()->user()->phone ?? auth()->user()->whatsapp_no ?? '';

        $firstCategory = LeaveCategory::active()->forStudent()->first();
        if ($firstCategory) {
            $this->categoryId = $firstCategory->id;
        }
    }

    #[Computed]
    public function categories()
    {
        return LeaveCategory::active()->forStudent()->orderBy('name')->get();
    }

    #[Computed]
    public function balances(): array
    {
        $leaveService = app(LeaveManagementService::class);
        return $leaveService->getUserLeaveBalances(auth()->user());
    }

    #[Computed]
    public function myApplications()
    {
        return LeaveApplication::where('user_id', auth()->id())
            ->with(['category', 'approver'])
            ->latest('id')
            ->paginate($this->perPage);
    }

    public function apply(LeaveManagementService $leaveService): void
    {
        $this->validate([
            'categoryId' => 'required|exists:leave_categories,id',
            'fromDate' => 'required|date',
            'toDate' => 'required|date|after_or_equal:fromDate',
            'reason' => 'required|string|min:5|max:1000',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        try {
            $attachmentPath = null;
            if ($this->attachment) {
                $attachmentPath = $this->attachment->store('leave_attachments', 'public');
            }

            $leaveService->applyLeave(auth()->user(), [
                'leave_category_id' => $this->categoryId,
                'from_date' => $this->fromDate,
                'to_date' => $this->toDate,
                'is_half_day' => $this->isHalfDay,
                'half_day_type' => $this->halfDayType,
                'reason' => $this->reason,
                'emergency_contact' => $this->emergencyContact,
                'attachment_path' => $attachmentPath,
            ]);

            Toast::dispatch($this, 'success', __('Your leave application has been submitted successfully for administration approval.'));
            $this->reason = '';
            $this->attachment = null;
            $this->dispatch('modal-close', name: 'apply-leave-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Submission failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function confirmCancelApplication(int $id): void
    {
        $this->cancelApplicationId = $id;
        $this->dispatch('modal-open', name: 'cancel-leave-modal');
    }

    public function cancelApplication(LeaveManagementService $leaveService): void
    {
        if (! $this->cancelApplicationId) {
            return;
        }

        try {
            $app = LeaveApplication::where('user_id', auth()->id())->findOrFail($this->cancelApplicationId);
            if ($app->status !== LeaveApplication::STATUS_PENDING) {
                Toast::dispatch($this, 'error', __('Only pending leave applications can be cancelled directly.'));
                $this->dispatch('modal-close', name: 'cancel-leave-modal');
                $this->cancelApplicationId = null;
                return;
            }

            $leaveService->cancelLeave($app, auth()->user(), __('Cancelled by student.'));
            Toast::dispatch($this, 'success', __('Leave application cancelled.'));
            $this->dispatch('modal-close', name: 'cancel-leave-modal');
            $this->cancelApplicationId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to cancel: :err', ['err' => $e->getMessage()]));
        }
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('My Leaves & Leave History') }}</h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
                    {{ __('Student Portal') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Apply for personal or medical leaves, view leave quota balances, and track approval status.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('student.attendance.index') }}" wire:navigate>
                <x-ui.button variant="outline" size="sm" class="cursor-pointer">
                    <x-icon name="calendar-check" class="w-4 h-4 mr-1.5" />
                    {{ __('View Attendance Dossier') }}
                </x-ui.button>
            </a>

            <x-ui.button
                variant="default"
                size="sm"
                x-on:click="$dispatch('modal-open', { name: 'apply-leave-modal' })"
                class="cursor-pointer bg-primary text-primary-foreground font-semibold"
            >
                <x-icon name="plus" class="w-4 h-4 mr-1.5" />
                {{ __('Apply for Leave') }}
            </x-ui.button>
        </div>
    </div>

    <!-- Leave Balances Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach($this->balances as $bal)
            <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-foreground">{{ $bal['category']->name }}</span>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border {{ $bal['category']->badgeColorClass() }}">
                        {{ $bal['category']->code }}
                    </span>
                </div>

                <div class="mt-3 flex items-baseline justify-between">
                    <div>
                        <span class="text-2xl font-bold font-mono text-emerald-600 dark:text-emerald-400">{{ $bal['remaining_days'] }}</span>
                        <span class="text-[11px] text-muted-foreground block">{{ __('Days Left') }}</span>
                    </div>
                    <div class="text-right text-xs font-mono text-muted-foreground">
                        <div>Used: <strong class="text-foreground">{{ $bal['used_days'] }}</strong></div>
                        <div>Total: {{ $bal['total_days'] }}</div>
                    </div>
                </div>

                @if($bal['pending_days'] > 0)
                    <div class="mt-2 text-[10px] text-amber-600 dark:text-amber-400 font-semibold">
                        {{ $bal['pending_days'] }} {{ __('days pending approval') }}
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <!-- Leave History Table -->
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="px-4 py-3 border-b border-border flex items-center justify-between">
            <h2 class="text-sm font-semibold text-foreground">
                {{ __('My Leave Application History') }}
            </h2>
            <span class="text-xs text-muted-foreground">
                {{ __('Total :count applications', ['count' => $this->myApplications->total()]) }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs divide-y divide-border">
                <thead class="bg-muted/50 text-muted-foreground font-medium uppercase text-[11px] tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('Application No') }}</th>
                        <th class="px-4 py-3">{{ __('Category') }}</th>
                        <th class="px-4 py-3">{{ __('Dates') }}</th>
                        <th class="px-4 py-3">{{ __('Days') }}</th>
                        <th class="px-4 py-3">{{ __('Reason') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    @forelse($this->myApplications as $app)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-4 py-3 font-mono font-medium text-foreground">
                                {{ $app->application_no }}
                                <span class="text-[10px] text-muted-foreground block font-sans">
                                    {{ $app->created_at ? $app->created_at->format('d M, Y') : '' }}
                                </span>
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
                                    <span class="text-[9px] text-purple-600 dark:text-purple-400 block font-sans">
                                        ({{ str_replace('_', ' ', $app->half_day_type) }})
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3 max-w-xs truncate text-foreground" title="{{ $app->reason }}">
                                {{ $app->reason }}
                            </td>

                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border {{ $app->statusBadgeClass() }}">
                                    {{ ucfirst($app->status) }}
                                </span>
                                @if($app->action_remarks)
                                    <span class="text-[10px] text-muted-foreground block mt-0.5 italic max-w-xs truncate" title="{{ $app->action_remarks }}">
                                        "{{ $app->action_remarks }}"
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-right">
                                @if($app->status === 'pending')
                                    <x-ui.button
                                        variant="outline"
                                        size="xs"
                                        wire:click="confirmCancelApplication({{ $app->id }})"
                                        class="cursor-pointer text-xs text-rose-600 hover:bg-rose-500/10"
                                    >
                                        {{ __('Cancel') }}
                                    </x-ui.button>
                                @elseif($app->is_synced_to_attendance)
                                    <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-semibold flex items-center justify-end gap-1">
                                        <x-icon name="check-check" class="w-3.5 h-3.5" />
                                        {{ __('In Attendance') }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-muted-foreground">
                                <x-icon name="calendar" class="w-10 h-10 mx-auto text-muted-foreground/40 mb-2" />
                                <p class="text-sm font-medium">{{ __('You have not submitted any leave applications yet.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->myApplications->hasPages())
            <div class="px-4 py-3 border-t border-border">
                {{ $this->myApplications->links() }}
            </div>
        @endif
    </div>

    <!-- Apply Modal -->
    <x-ui.modal name="apply-leave-modal" :title="__('Apply for Leave')">
        <form wire:submit="apply" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Leave Category') }} *</label>
                <select wire:model.live="categoryId" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg">
                    @foreach($this->categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }}) - {{ $c->annual_quota }} days/yr</option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('From Date') }} *</label>
                    <input type="date" wire:model.live="fromDate" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                    @error('fromDate') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('To Date') }} *</label>
                    <input type="date" wire:model.live="toDate" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                    @error('toDate') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="p-3 rounded-lg border border-border bg-muted/20 space-y-2">
                <label class="flex items-center gap-2 cursor-pointer text-xs font-medium text-foreground">
                    <input type="checkbox" wire:model.live="isHalfDay" class="rounded border-border text-primary focus:ring-primary" />
                    <span>{{ __('Half-Day Leave (0.5 Day)') }}</span>
                </label>

                @if($isHalfDay)
                    <div class="grid grid-cols-2 gap-2 pt-2 border-t border-border/60">
                        <label class="flex items-center gap-1.5 text-xs text-foreground cursor-pointer">
                            <input type="radio" value="first_half" wire:model="halfDayType" class="text-primary focus:ring-primary" />
                            <span>{{ __('First Half (Morning)') }}</span>
                        </label>
                        <label class="flex items-center gap-1.5 text-xs text-foreground cursor-pointer">
                            <input type="radio" value="second_half" wire:model="halfDayType" class="text-primary focus:ring-primary" />
                            <span>{{ __('Second Half (Afternoon)') }}</span>
                        </label>
                    </div>
                @endif
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Purpose of Leave / Reason') }} *</label>
                <textarea
                    wire:model="reason"
                    rows="3"
                    placeholder="{{ __('Detailed reason for taking leave...') }}"
                    class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg"
                ></textarea>
                @error('reason') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Emergency Contact Number') }}</label>
                    <input type="text" wire:model="emergencyContact" placeholder="+91..." class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg font-mono" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Document / Medical Certificate') }}</label>
                    <input type="file" wire:model="attachment" accept=".pdf,.jpg,.jpeg,.png" class="w-full text-xs file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-primary file:text-primary-foreground file:text-xs" />
                </div>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'apply-leave-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-primary text-primary-foreground font-semibold">
                    <x-icon name="send" class="w-4 h-4 mr-1.5" />
                    {{ __('Submit Application') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Cancel Leave Application Confirmation Modal -->
    <x-ui.modal name="cancel-leave-modal" :title="__('Cancel Leave Application')">
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-full bg-rose-500/10 text-rose-600 flex items-center justify-center shrink-0">
                    <x-icon name="alert-circle" class="w-5 h-5" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-foreground">{{ __('Cancel Application?') }}</h3>
                    <p class="text-xs text-muted-foreground mt-1">
                        {{ __('Are you sure you want to cancel this pending leave application? Once cancelled, you will need to re-apply if you still require leave.') }}
                    </p>
                </div>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'cancel-leave-modal' })">
                    {{ __('Keep Application') }}
                </x-ui.button>
                <x-ui.button type="button" variant="destructive" size="sm" wire:click="cancelApplication" class="bg-rose-600 hover:bg-rose-700 text-white font-semibold">
                    {{ __('Yes, Cancel Application') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
