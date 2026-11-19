<?php

use App\Models\HostelExtensionRequest;
use App\Services\Hostel\HostelService;
use App\Support\Toast;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Hostel Accommodation Extension Requests')] class extends Component {
    use WithPagination;

    #[Url(as: 'status')]
    public string $statusFilter = 'pending'; // pending, approved, rejected, all

    #[Url(as: 'q')]
    public string $search = '';

    public int $perPage = 15;

    // Approve Modal State
    public ?int $approvingRequestId = null;
    public string $approvalNotes = '';

    // Reject Modal State
    public ?int $rejectingRequestId = null;
    public string $rejectionReason = '';

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'pending' => HostelExtensionRequest::pending()->count(),
            'approved' => HostelExtensionRequest::approved()->count(),
            'rejected' => HostelExtensionRequest::rejected()->count(),
            'total' => HostelExtensionRequest::count(),
        ];
    }

    public function render()
    {
        $query = HostelExtensionRequest::with([
            'user.studentProfile',
            'allocation.room.building',
            'allocation.bed',
            'reviewer',
        ]);

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if (! empty($this->search)) {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('request_no', 'like', "%{$s}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$s}%")->orWhere('student_id', 'like', "%{$s}%"))
                    ->orWhere('reason', 'like', "%{$s}%");
            });
        }

        $requests = $query->latest('id')->paginate($this->perPage);

        return view('pages.admin.hostel.⚡requests', [
            'requests' => $requests,
        ]);
    }

    public function openApproveModal(int $id): void
    {
        $this->resetErrorBag();
        $this->approvingRequestId = $id;
        $this->approvalNotes = '';
        $this->dispatch('modal-open', name: 'request-approve-modal');
    }

    public function approveRequest(): void
    {
        if (! $this->approvingRequestId) {
            return;
        }

        try {
            $request = HostelExtensionRequest::findOrFail($this->approvingRequestId);
            HostelService::approveExtensionRequest(
                $request,
                $this->approvalNotes ?: null,
                auth()->id()
            );

            Toast::dispatch($this, 'success', __('Extension request approved and accommodation extended.'));
            $this->dispatch('modal-close', name: 'request-approve-modal');
            $this->approvingRequestId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openRejectModal(int $id): void
    {
        $this->resetErrorBag();
        $this->rejectingRequestId = $id;
        $this->rejectionReason = '';
        $this->dispatch('modal-open', name: 'request-reject-modal');
    }

    public function rejectRequest(): void
    {
        $this->validate([
            'rejectionReason' => 'required|string|min:5|max:500',
        ]);

        if (! $this->rejectingRequestId) {
            return;
        }

        try {
            $request = HostelExtensionRequest::findOrFail($this->rejectingRequestId);
            HostelService::rejectExtensionRequest(
                $request,
                $this->rejectionReason,
                auth()->id()
            );

            Toast::dispatch($this, 'success', __('Extension request rejected with documented reason.'));
            $this->dispatch('modal-close', name: 'request-reject-modal');
            $this->rejectingRequestId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Accommodation Extension Requests') }}</h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20">
                    {{ __('Resident Requests Queue') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Review, approve or reject stay extension requests filed by students and staff from their resident portal.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.settings.hostel') }}" wire:navigate>
                <x-ui.button variant="outline" size="sm" class="cursor-pointer">
                    <x-icon name="settings" class="w-4 h-4 mr-1.5" />
                    {{ __('Extension Policy Settings') }}
                </x-ui.button>
            </a>
        </div>
    </div>

    <!-- Status Tabs & Filter Strip -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 p-4 rounded-2xl border border-border bg-card shadow-xs">
        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                wire:click="$set('statusFilter', 'pending')"
                @class([
                    'px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center gap-1.5',
                    'bg-amber-500 text-white shadow-xs' => $statusFilter === 'pending',
                    'bg-secondary text-secondary-foreground hover:bg-secondary/80' => $statusFilter !== 'pending',
                ])
            >
                <span>{{ __('Pending Review') }}</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-white/20">
                    {{ $this->counts['pending'] }}
                </span>
            </button>

            <button
                type="button"
                wire:click="$set('statusFilter', 'approved')"
                @class([
                    'px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center gap-1.5',
                    'bg-emerald-600 text-white shadow-xs' => $statusFilter === 'approved',
                    'bg-secondary text-secondary-foreground hover:bg-secondary/80' => $statusFilter !== 'approved',
                ])
            >
                <span>{{ __('Approved') }}</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-white/20">
                    {{ $this->counts['approved'] }}
                </span>
            </button>

            <button
                type="button"
                wire:click="$set('statusFilter', 'rejected')"
                @class([
                    'px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center gap-1.5',
                    'bg-rose-600 text-white shadow-xs' => $statusFilter === 'rejected',
                    'bg-secondary text-secondary-foreground hover:bg-secondary/80' => $statusFilter !== 'rejected',
                ])
            >
                <span>{{ __('Rejected') }}</span>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-white/20">
                    {{ $this->counts['rejected'] }}
                </span>
            </button>

            <button
                type="button"
                wire:click="$set('statusFilter', 'all')"
                @class([
                    'px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer',
                    'bg-primary text-primary-foreground shadow-xs' => $statusFilter === 'all',
                    'bg-secondary text-secondary-foreground hover:bg-secondary/80' => $statusFilter !== 'all',
                ])
            >
                {{ __('All Requests') }} ({{ $this->counts['total'] }})
            </button>
        </div>

        <!-- Search input -->
        <div class="relative w-full md:w-64">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search by request #, student...') }}"
                class="w-full h-9 pl-8 pr-3 text-xs rounded-xl border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
            />
            <x-icon name="search" class="w-3.5 h-3.5 text-muted-foreground absolute left-2.5 top-3" />
        </div>
    </div>

    <!-- Requests Table -->
    <div class="rounded-2xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-secondary/40 text-muted-foreground uppercase font-semibold border-b border-border">
                    <tr>
                        <th class="p-3.5">{{ __('Request #') }}</th>
                        <th class="p-3.5">{{ __('Resident') }}</th>
                        <th class="p-3.5">{{ __('Room & Bed') }}</th>
                        <th class="p-3.5">{{ __('Current End Date') }}</th>
                        <th class="p-3.5">{{ __('Requested Extension') }}</th>
                        <th class="p-3.5">{{ __('Reason Submitted') }}</th>
                        <th class="p-3.5">{{ __('Status') }}</th>
                        <th class="p-3.5 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($requests as $req)
                        <tr class="hover:bg-secondary/20 transition-colors" wire:key="req-row-{{ $req->id }}">
                            <!-- Request # -->
                            <td class="p-3.5 font-bold font-mono text-foreground">
                                {{ $req->request_no }}
                                <div class="text-[10px] text-muted-foreground font-sans">
                                    {{ $req->created_at->format('d M Y, h:i A') }}
                                </div>
                            </td>

                            <!-- Resident -->
                            <td class="p-3.5">
                                <div class="font-bold text-foreground">{{ $req->user->name ?? 'N/A' }}</div>
                                <div class="text-[11px] text-muted-foreground">
                                    @if ($req->user->student_id)
                                        <span>{{ $req->user->student_id }}</span> &bull;
                                    @endif
                                    <span>{{ $req->user->email }}</span>
                                </div>
                            </td>

                            <!-- Room & Bed -->
                            <td class="p-3.5">
                                <div class="font-semibold text-foreground">
                                    {{ $req->allocation->room->building->code ?? '' }} - {{ $req->allocation->room->room_number ?? 'Room' }}
                                </div>
                                <div class="text-[11px] text-muted-foreground">
                                    {{ $req->allocation->bed->bed_number ?? 'Bed' }}
                                </div>
                            </td>

                            <!-- Current End Date -->
                            <td class="p-3.5">
                                <span class="font-semibold text-foreground">
                                    {{ $req->current_end_date ? $req->current_end_date->format('d M Y') : __('Indefinite') }}
                                </span>
                            </td>

                            <!-- Requested Extension -->
                            <td class="p-3.5">
                                <div class="font-bold text-primary">
                                    {{ $req->requested_end_date->format('d M Y') }}
                                </div>
                                <div class="text-[11px] text-muted-foreground">
                                    +{{ $req->extension_duration_value }} {{ ucfirst($req->extension_duration_type) }}
                                </div>
                            </td>

                            <!-- Reason -->
                            <td class="p-3.5 max-w-xs">
                                <p class="text-xs text-foreground line-clamp-2" title="{{ $req->reason }}">
                                    {{ $req->reason }}
                                </p>
                                @if ($req->admin_notes)
                                    <div class="mt-1 text-[11px] text-muted-foreground p-1.5 rounded bg-secondary/50">
                                        <span class="font-bold">{{ __('Admin Note:') }}</span> {{ $req->admin_notes }}
                                    </div>
                                @endif
                            </td>

                            <!-- Status -->
                            <td class="p-3.5">
                                <span @class([
                                    'px-2.5 py-0.5 rounded text-[10px] font-bold uppercase',
                                    'bg-amber-500/10 text-amber-600 border border-amber-500/20' => $req->status === 'pending',
                                    'bg-emerald-500/10 text-emerald-600 border border-emerald-500/20' => $req->status === 'approved',
                                    'bg-rose-500/10 text-rose-600 border border-rose-500/20' => $req->status === 'rejected',
                                ])>
                                    {{ ucfirst($req->status) }}
                                </span>
                                @if ($req->reviewed_by)
                                    <div class="text-[10px] text-muted-foreground mt-0.5">
                                        {{ __('By') }} {{ $req->reviewer->name ?? 'Admin' }}
                                    </div>
                                @endif
                            </td>

                            <!-- Actions -->
                            <td class="p-3.5 text-right">
                                @if ($req->isPending())
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-ui.button
                                            variant="default"
                                            size="sm"
                                            wire:click="openApproveModal({{ $req->id }})"
                                            class="cursor-pointer text-xs h-7 px-2.5 bg-emerald-600 hover:bg-emerald-700 text-white"
                                        >
                                            <x-icon name="check" class="w-3.5 h-3.5 mr-1" />
                                            {{ __('Approve') }}
                                        </x-ui.button>

                                        <x-ui.button
                                            variant="outline"
                                            size="sm"
                                            wire:click="openRejectModal({{ $req->id }})"
                                            class="cursor-pointer text-xs h-7 px-2.5 text-rose-600 dark:text-rose-400 border-rose-500/20 hover:bg-rose-500/10"
                                        >
                                            <x-icon name="x" class="w-3.5 h-3.5 mr-1" />
                                            {{ __('Reject') }}
                                        </x-ui.button>
                                    </div>
                                @else
                                    <span class="text-xs text-muted-foreground">{{ __('Processed') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-8 text-center text-muted-foreground">
                                {{ __('No accommodation extension requests found.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($requests->hasPages())
            <div class="p-4 border-t border-border">
                {{ $requests->links() }}
            </div>
        @endif
    </div>

    <!-- Modal: Approve Request -->
    <x-ui.modal name="request-approve-modal" max-width="max-w-md" :title="__('Approve Accommodation Extension')">
        <form wire:submit="approveRequest" class="space-y-4">
            <p class="text-xs text-muted-foreground">
                {{ __('Approving this request will immediately update the resident’s accommodation end date to the requested timeline and mark the allocation as extended.') }}
            </p>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Administrative Approval Remarks (Optional)') }}</label>
                <textarea
                    wire:model="approvalNotes"
                    rows="3"
                    placeholder="{{ __('e.g. Approved per Academic Council circular / Examination extension...') }}"
                    class="w-full p-2.5 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                ></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('request-approve-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="bg-emerald-600 hover:bg-emerald-700 text-white"
                >
                    {{ __('Confirm Approval') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Modal: Reject Request -->
    <x-ui.modal name="request-reject-modal" max-width="max-w-md" :title="__('Reject Accommodation Extension')">
        <form wire:submit="rejectRequest" class="space-y-4">
            <p class="text-xs text-muted-foreground">
                {{ __('Please specify a clear reason for denying this extension request. The reason will be visible to the resident on their student/staff dashboard.') }}
            </p>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Mandatory Rejection Reason') }} *</label>
                <textarea
                    wire:model="rejectionReason"
                    rows="4"
                    placeholder="{{ __('Explain why the extension cannot be granted (e.g. room scheduled for renovation, batch tenure concluded, capacity full for upcoming batch)...') }}"
                    class="w-full p-2.5 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    required
                ></textarea>
                @error('rejectionReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('request-reject-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="bg-rose-600 hover:bg-rose-700 text-white"
                >
                    {{ __('Confirm Rejection') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
