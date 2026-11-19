<?php

use App\Models\HostelAllocation;
use App\Models\HostelExtensionRequest;
use App\Models\Setting;
use App\Services\FileUploadService;
use App\Services\Hostel\HostelService;
use App\Support\Toast;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('My Hostel Accommodation')] class extends Component {
    use WithFileUploads;

    // Extension Request Modal Form State
    public string $extensionType = 'months'; // months, days, custom
    public int $extensionValue = 3;
    public ?string $extensionCustomDate = null;
    public string $extensionReason = '';

    // Soft Copy Upload State
    public $softCopyFile = null;

    #[Computed]
    public function currentAllocation(): ?HostelAllocation
    {
        return HostelAllocation::with([
            'room.building.caretakers',
            'room.floor',
            'room.beds.currentAllocation.user',
            'bed',
            'user.studentProfile',
            'user.activeEnrollment.course',
            'user.activeEnrollment.batch',
            'user.activeEnrollment.section',
        ])
            ->where('user_id', auth()->id())
            ->active()
            ->latest('id')
            ->first();
    }

    #[Computed]
    public function previousAllocations()
    {
        return HostelAllocation::with(['room.building', 'bed'])
            ->where('user_id', auth()->id())
            ->whereIn('status', ['vacated', 'cancelled'])
            ->latest('id')
            ->get();
    }

    #[Computed]
    public function roommates()
    {
        $current = $this->currentAllocation;
        if (! $current || ! $current->room->isSharing()) {
            return collect();
        }

        return $current->room->beds
            ->filter(fn ($b) => $b->id !== $current->hostel_bed_id && $b->currentAllocation)
            ->map(fn ($b) => $b->currentAllocation);
    }

    #[Computed]
    public function extensionRequests()
    {
        return HostelExtensionRequest::with('reviewer')
            ->where('user_id', auth()->id())
            ->latest('id')
            ->get();
    }

    #[Computed]
    public function isExtensionAllowed(): bool
    {
        return HostelService::isExtensionRequestAllowed();
    }

    #[Computed]
    public function isSoftCopyMandatory(): bool
    {
        return HostelService::isSoftCopyMandatory();
    }

    #[Computed]
    public function rulesNotice(): string
    {
        return (string) Setting::get('hostel.rules_notice', '');
    }

    public function openExtensionModal(): void
    {
        $this->resetErrorBag();
        $this->extensionType = 'months';
        $this->extensionValue = 3;
        $this->extensionCustomDate = null;
        $this->extensionReason = '';
        $this->dispatch('modal-open', name: 'student-extension-modal');
    }

    public function submitExtensionRequest(): void
    {
        $this->validate([
            'extensionType' => 'required|in:months,days,custom',
            'extensionValue' => 'nullable|required_if:extensionType,months,days|integer|min:1',
            'extensionCustomDate' => 'nullable|required_if:extensionType,custom|date|after:today',
            'extensionReason' => 'required|string|min:10|max:1000',
        ]);

        $current = $this->currentAllocation;
        if (! $current) {
            Toast::dispatch($this, 'error', __('No active accommodation found to extend.'));

            return;
        }

        $baseDate = $current->end_date ? $current->end_date->copy() : now();
        $requestedDate = match ($this->extensionType) {
            'months' => $baseDate->copy()->addMonths($this->extensionValue),
            'days' => $baseDate->copy()->addDays($this->extensionValue),
            'custom' => Carbon::parse($this->extensionCustomDate),
        };

        try {
            HostelService::submitExtensionRequest(
                $current,
                $requestedDate->toDateString(),
                $this->extensionType,
                $this->extensionValue,
                $this->extensionReason,
                auth()->id()
            );

            Toast::dispatch($this, 'success', __('Extension request submitted successfully for administrative review.'));
            $this->dispatch('modal-close', name: 'student-extension-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function uploadSoftCopy(): void
    {
        $this->validate([
            'softCopyFile' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $current = $this->currentAllocation;
        if (! $current) {
            return;
        }

        try {
            $path = FileUploadService::store(
                $this->softCopyFile,
                'hostel/forms',
                'hostel_form',
                'public',
                $current->soft_copy_path
            );

            $current->update([
                'soft_copy_path' => $path,
                'soft_copy_uploaded_at' => now(),
                'soft_copy_uploaded_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            Toast::dispatch($this, 'success', __('Hostel admission form soft-copy uploaded successfully.'));
            $this->softCopyFile = null;
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
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('My Hostel Accommodation') }}</h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                    {{ __('Resident Dossier') }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('View your allocated hostel room, bed, stay timeline, roommates, and submission status.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($this->currentAllocation)
                <a
                    href="{{ route('hostel.form.download') }}"
                    target="_blank"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold bg-secondary hover:bg-secondary/80 text-foreground border border-border shadow-xs cursor-pointer transition-colors"
                    title="{{ __('Download your official hostel admission & accommodation form') }}"
                >
                    <x-icon name="file-down" class="w-4 h-4 text-primary" />
                    {{ __('Download Hostel Form (PDF)') }}
                </a>
            @endif

            @if ($this->currentAllocation && $this->isExtensionAllowed)
                <x-ui.button
                    type="button"
                    variant="default"
                    size="sm"
                    wire:click="openExtensionModal"
                    class="bg-primary text-primary-foreground cursor-pointer shadow-xs"
                >
                    <x-icon name="calendar-plus" class="w-4 h-4 mr-1.5" />
                    {{ __('Request Stay Extension') }}
                </x-ui.button>
            @endif
        </div>
    </div>

    @if ($this->currentAllocation)
        @php
            $alloc = $this->currentAllocation;
        @endphp
        <!-- Active Room Allotment Card -->
        <div class="p-6 rounded-2xl border border-border bg-card shadow-xs space-y-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-4 border-b border-border">
                <div class="flex items-center gap-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-primary/10 text-primary flex items-center justify-center shadow-xs">
                        <x-icon name="bed" class="w-6 h-6" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-xl font-extrabold text-foreground tracking-tight">
                                {{ $alloc->room->building->name ?? '' }}
                            </h2>
                            <span class="px-2 py-0.5 rounded text-xs font-bold uppercase bg-secondary text-secondary-foreground">
                                {{ $alloc->room->building->code ?? '' }}
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ $alloc->room->floor->name ?? 'Floor' }} &bull; {{ __('Allotment Ref: #') }}{{ $alloc->allocation_no }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <span class="px-3 py-1 rounded-full text-xs font-bold uppercase bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                        {{ ucfirst($alloc->status) }}
                    </span>
                    <span class="px-3 py-1 rounded-full text-xs font-bold bg-secondary text-secondary-foreground border border-border">
                        {{ $alloc->room->isSharing() ? $alloc->room->capacity . '-Sharing' : __('Single Non-Sharing') }}
                    </span>
                </div>
            </div>

            <!-- Key Metrics Grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="p-4 rounded-xl border border-border bg-background">
                    <span class="text-[10px] text-muted-foreground uppercase font-bold tracking-wider block">{{ __('Room Number') }}</span>
                    <span class="text-lg font-extrabold text-foreground mt-1 block">{{ $alloc->room->room_number }}</span>
                    <span class="text-[11px] text-muted-foreground">{{ $alloc->room->gender_label }}</span>
                </div>

                <div class="p-4 rounded-xl border border-border bg-background">
                    <span class="text-[10px] text-muted-foreground uppercase font-bold tracking-wider block">{{ __('Assigned Bed') }}</span>
                    <span class="text-lg font-extrabold text-primary mt-1 block">{{ $alloc->bed->bed_number }}</span>
                    <span class="text-[11px] text-emerald-600 font-medium">{{ __('Occupied by You') }}</span>
                </div>

                <div class="p-4 rounded-xl border border-border bg-background">
                    <span class="text-[10px] text-muted-foreground uppercase font-bold tracking-wider block">{{ __('Check-In Date') }}</span>
                    <span class="text-base font-bold text-foreground mt-1 block">{{ $alloc->start_date->format('d M Y') }}</span>
                    <span class="text-[11px] text-muted-foreground">{{ __('Active Stay') }}</span>
                </div>

                <div class="p-4 rounded-xl border border-border bg-background">
                    <span class="text-[10px] text-muted-foreground uppercase font-bold tracking-wider block">{{ __('Stay Valid Until') }}</span>
                    <span class="text-base font-bold text-foreground mt-1 block">
                        {{ $alloc->end_date ? $alloc->end_date->format('d M Y') : __('Indefinite') }}
                    </span>
                    @if ($alloc->end_date)
                        <span @class([
                            'text-[11px] font-bold block',
                            'text-emerald-600' => $alloc->daysRemaining() > 15,
                            'text-amber-600' => $alloc->daysRemaining() <= 15 && $alloc->daysRemaining() >= 0,
                            'text-rose-600' => $alloc->daysRemaining() < 0,
                        ])>
                            {{ $alloc->daysRemaining() }} {{ __('Days Remaining') }}
                        </span>
                    @endif
                </div>
            </div>

            <!-- Amenities -->
            @if (! empty($alloc->room->amenities) && is_array($alloc->room->amenities))
                <div>
                    <span class="text-xs font-semibold text-muted-foreground uppercase block mb-2">{{ __('Room Amenities') }}</span>
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($alloc->room->amenities as $am)
                            <span class="px-2.5 py-1 rounded-lg bg-secondary text-xs font-medium text-foreground border border-border">
                                {{ ucfirst(str_replace('_', ' ', $am)) }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Academic Enrollment & Hostel Administration Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Academic Enrollment -->
                @if ($alloc->isResidentStudent() || $alloc->course_name)
                    <div class="p-4 rounded-xl border border-primary/20 bg-primary/5 space-y-2.5">
                        <div class="flex items-center gap-2 pb-1.5 border-b border-primary/10">
                            <x-icon name="graduation-cap" class="w-4 h-4 text-primary" />
                            <h3 class="font-bold text-xs text-primary uppercase tracking-wider">{{ __('Your Academic Enrollment') }}</h3>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div class="bg-background/80 p-2 rounded-lg border border-border">
                                <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Student ID / URN') }}</span>
                                <span class="font-bold text-foreground mt-0.5 block">{{ $alloc->student_identifier }}</span>
                            </div>
                            <div class="bg-background/80 p-2 rounded-lg border border-border">
                                <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Enrollment ID') }}</span>
                                <span class="font-bold text-foreground mt-0.5 block">{{ $alloc->enrollment_no }}</span>
                            </div>
                            <div class="bg-background/80 p-2 rounded-lg border border-border">
                                <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Course & Batch') }}</span>
                                <span class="font-bold text-foreground mt-0.5 block">{{ $alloc->course_name }} ({{ $alloc->batch_name }})</span>
                            </div>
                            <div class="bg-background/80 p-2 rounded-lg border border-border">
                                <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Assigned Section') }}</span>
                                <span class="font-bold text-foreground mt-0.5 block">{{ $alloc->section_name }}</span>
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Hostel Management & Caretakers on Duty -->
                <div class="p-4 rounded-xl border border-border bg-secondary/20 space-y-2.5">
                    <div class="flex items-center gap-2 pb-1.5 border-b border-border">
                        <x-icon name="shield" class="w-4 h-4 text-amber-500" />
                        <h3 class="font-bold text-xs text-foreground uppercase tracking-wider">{{ __('Hostel Management & Caretakers') }}</h3>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div class="bg-background p-2 rounded-lg border border-border">
                            <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Hostel Manager') }}</span>
                            <span class="font-bold text-foreground">{{ $alloc->room?->building?->manager_name ?? __('N/A') }}</span>
                            @if ($alloc->room?->building?->manager_contact)
                                <span class="text-[11px] text-muted-foreground block">{{ $alloc->room->building->manager_contact }}</span>
                            @endif
                        </div>
                        <div class="bg-background p-2 rounded-lg border border-border">
                            <span class="text-[10px] text-muted-foreground uppercase block">{{ __('Warden') }}</span>
                            <span class="font-bold text-foreground">{{ $alloc->room?->building?->warden_name ?? __('N/A') }}</span>
                            @if ($alloc->room?->building?->warden_contact)
                                <span class="text-[11px] text-muted-foreground block">{{ $alloc->room->building->warden_contact }}</span>
                            @endif
                        </div>
                    </div>
                    @if ($alloc->room?->building?->caretakers && $alloc->room->building->caretakers->isNotEmpty())
                        <div class="pt-1 space-y-1">
                            <span class="text-[10px] font-semibold text-muted-foreground uppercase">{{ __('Caretakers on duty:') }}</span>
                            <div class="flex flex-wrap items-center gap-1.5">
                                @foreach ($alloc->room->building->caretakers as $ct)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] bg-background border border-border text-foreground">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $ct->is_primary ? 'bg-emerald-500' : 'bg-muted-foreground' }}"></span>
                                        <strong>{{ $ct->name }}</strong>
                                        <span class="text-[10px] text-muted-foreground">({{ ucfirst($ct->shift) }}: {{ $ct->phone }})</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <!-- 2-Col Form Tracking & Roommates -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 pt-2">
                <!-- Form Submission Tracking & Upload Card -->
                <div class="p-5 rounded-xl border border-border bg-background space-y-4">
                    <div class="flex items-center justify-between pb-2 border-b border-border">
                        <div class="flex items-center gap-2">
                            <x-icon name="file-check" class="w-4 h-4 text-primary" />
                            <h3 class="font-bold text-sm text-foreground">{{ __('Hostel Admission Form') }}</h3>
                        </div>
                        <a
                            href="{{ route('hostel.form.download') }}"
                            target="_blank"
                            class="inline-flex items-center gap-1 text-xs font-bold text-primary hover:underline"
                        >
                            <x-icon name="download" class="w-3.5 h-3.5" />
                            {{ __('Download PDF Form') }}
                        </a>
                    </div>

                    <!-- Hardcopy Status -->
                    <div class="flex items-center justify-between p-3 rounded-lg bg-secondary/30">
                        <div>
                            <span class="text-xs font-bold text-foreground block">{{ __('Signed Hardcopy Form') }}</span>
                            <span class="text-[11px] text-muted-foreground">
                                {{ $alloc->hard_copy_submitted ? __('Submitted to hostel office on ') . $alloc->hard_copy_submitted_at?->format('d M Y') : __('Pending physical submission to hostel office') }}
                            </span>
                        </div>
                        @if ($alloc->hard_copy_submitted)
                            <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-600 dark:text-emerald-400">
                                <x-icon name="check-circle" class="w-4 h-4" />
                                {{ __('Submitted') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 text-xs font-bold text-amber-600 dark:text-amber-400">
                                <x-icon name="alert-circle" class="w-4 h-4" />
                                {{ __('Due to Office') }}
                            </span>
                        @endif
                    </div>

                    <!-- Softcopy Status & Upload -->
                    <div class="p-3 rounded-lg bg-secondary/30 space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="text-xs font-bold text-foreground block">{{ __('Uploaded Soft-Copy Form') }}</span>
                                <span class="text-[11px] text-muted-foreground">
                                    @if ($this->isSoftCopyMandatory)
                                        <span class="text-rose-500 font-semibold">{{ __('Mandatory: ') }}</span>
                                    @endif
                                    {{ $alloc->soft_copy_path ? __('Digital copy on file') : __('No digital copy uploaded yet') }}
                                </span>
                            </div>

                            @if ($alloc->soft_copy_path)
                                <a
                                    href="{{ Storage::disk('public')->url($alloc->soft_copy_path) }}"
                                    target="_blank"
                                    class="text-xs font-bold text-primary hover:underline inline-flex items-center gap-1"
                                >
                                    <x-icon name="download" class="w-3.5 h-3.5" />
                                    {{ __('Download') }}
                                </a>
                            @endif
                        </div>

                        <!-- Upload Softcopy Form -->
                        <form wire:submit="uploadSoftCopy" class="space-y-2 pt-1 border-t border-border/60">
                            <label class="block text-[11px] font-semibold text-foreground">
                                {{ $alloc->soft_copy_path ? __('Re-upload / Replace Form Softcopy') : __('Upload Signed Softcopy (PDF / Scanned Image)') }}
                            </label>
                            <div class="flex items-center gap-2">
                                <input
                                    type="file"
                                    wire:model="softCopyFile"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    class="flex-1 text-xs text-muted-foreground file:mr-2 file:py-1 file:px-2.5 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-secondary file:text-foreground"
                                />
                                <x-ui.button
                                    type="submit"
                                    variant="default"
                                    size="sm"
                                    class="bg-primary text-primary-foreground text-xs"
                                    wire:loading.attr="disabled"
                                >
                                    <span wire:loading.remove wire:target="uploadSoftCopy">{{ __('Upload') }}</span>
                                    <span wire:loading wire:target="uploadSoftCopy">{{ __('...') }}</span>
                                </x-ui.button>
                            </div>
                            @error('softCopyFile') <span class="text-xs text-rose-500 block">{{ $message }}</span> @enderror
                        </form>
                    </div>
                </div>

                <!-- Roommates Card (if sharing) -->
                <div class="p-5 rounded-xl border border-border bg-background space-y-4">
                    <div class="flex items-center gap-2 pb-2 border-b border-border">
                        <x-icon name="users" class="w-4 h-4 text-primary" />
                        <h3 class="font-bold text-sm text-foreground">{{ __('Co-Residents in Room') }}</h3>
                    </div>

                    @if ($alloc->room->isSharing())
                        <div class="space-y-2.5">
                            @forelse ($this->roommates as $rm)
                                <div class="p-2.5 rounded-lg bg-secondary/30 flex items-center justify-between">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-7 h-7 rounded-full bg-secondary flex items-center justify-center font-bold text-xs">
                                            {{ substr($rm->user->name ?? 'U', 0, 2) }}
                                        </div>
                                        <div>
                                            <span class="text-xs font-bold text-foreground block">{{ $rm->user->name }}</span>
                                            <span class="text-[10px] text-muted-foreground">
                                                {{ $rm->bed->bed_number ?? '' }} &bull; {{ $rm->resident_type }}
                                            </span>
                                        </div>
                                    </div>
                                    <span class="text-[10px] text-muted-foreground">
                                        {{ __('Since') }} {{ $rm->start_date->format('M Y') }}
                                    </span>
                                </div>
                            @empty
                                <div class="p-4 text-center text-xs text-muted-foreground border border-dashed rounded-lg">
                                    {{ __('Other beds in this room are currently available.') }}
                                </div>
                            @endforelse
                        </div>
                    @else
                        <div class="p-6 text-center text-xs text-muted-foreground border border-dashed rounded-lg">
                            <x-icon name="user" class="w-8 h-8 text-muted-foreground mx-auto mb-2" />
                            {{ __('You are residing in a Single Occupancy (Non-Sharing) room.') }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @else
        <!-- No Active Accommodation Banner -->
        <div class="p-12 text-center rounded-2xl border border-dashed border-border bg-card">
            <x-icon name="bed" class="w-12 h-12 text-muted-foreground mx-auto mb-3" />
            <h3 class="text-base font-bold text-foreground">{{ __('No Active Hostel Accommodation') }}</h3>
            <p class="text-xs text-muted-foreground mt-1 max-w-md mx-auto">
                {{ __('You are not currently allotted a room in any hostel building. Please contact the hostel warden or administration office for room allotment.') }}
            </p>
        </div>
    @endif

    <!-- Extension Requests Status Table -->
    @if ($this->extensionRequests->isNotEmpty())
        <div class="rounded-2xl border border-border bg-card shadow-xs overflow-hidden">
            <div class="p-4 border-b border-border bg-secondary/20">
                <h3 class="text-sm font-bold text-foreground">{{ __('Your Stay Extension Requests') }}</h3>
                <p class="text-xs text-muted-foreground">{{ __('Status and review remarks for your requested timeline extensions') }}</p>
            </div>

            <div class="divide-y divide-border">
                @foreach ($this->extensionRequests as $req)
                    <div class="p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4" wire:key="req-{{ $req->id }}">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-mono font-bold text-xs text-foreground">{{ $req->request_no }}</span>
                                <span @class([
                                    'px-2 py-0.5 rounded text-[10px] font-bold uppercase',
                                    'bg-amber-500/10 text-amber-600' => $req->status === 'pending',
                                    'bg-emerald-500/10 text-emerald-600' => $req->status === 'approved',
                                    'bg-rose-500/10 text-rose-600' => $req->status === 'rejected',
                                ])>
                                    {{ ucfirst($req->status) }}
                                </span>
                            </div>
                            <div class="text-xs text-foreground mt-1">
                                {{ __('Requested End Date: ') }}<span class="font-bold">{{ $req->requested_end_date->format('d M Y') }}</span>
                                <span class="text-muted-foreground">(+{{ $req->extension_duration_value }} {{ ucfirst($req->extension_duration_type) }})</span>
                            </div>
                            <p class="text-xs text-muted-foreground mt-1">
                                {{ __('Reason: ') }}{{ $req->reason }}
                            </p>
                            @if ($req->admin_notes)
                                <div class="mt-2 text-xs p-2 rounded bg-secondary/50">
                                    <span class="font-bold text-foreground">{{ __('Admin Remark: ') }}</span>
                                    <span class="text-muted-foreground">{{ $req->admin_notes }}</span>
                                </div>
                            @endif
                        </div>

                        <div class="text-right text-[11px] text-muted-foreground shrink-0">
                            {{ __('Submitted on ') }}{{ $req->created_at->format('d M Y') }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Previous Accommodations History -->
    @if ($this->previousAllocations->isNotEmpty())
        <div class="rounded-2xl border border-border bg-card shadow-xs overflow-hidden">
            <div class="p-4 border-b border-border bg-secondary/20">
                <h3 class="text-sm font-bold text-foreground">{{ __('Past Hostel Accommodations History') }}</h3>
                <p class="text-xs text-muted-foreground">{{ __('Historical room stays and vacated tenures') }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-secondary/40 text-muted-foreground uppercase font-semibold border-b border-border">
                        <tr>
                            <th class="p-3.5">{{ __('Allotment Ref') }}</th>
                            <th class="p-3.5">{{ __('Hostel Building') }}</th>
                            <th class="p-3.5">{{ __('Room & Bed') }}</th>
                            <th class="p-3.5">{{ __('Check-In') }}</th>
                            <th class="p-3.5">{{ __('Vacated Date') }}</th>
                            <th class="p-3.5">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($this->previousAllocations as $pa)
                            <tr class="hover:bg-secondary/20 transition-colors">
                                <td class="p-3.5 font-mono font-bold text-foreground">{{ $pa->allocation_no }}</td>
                                <td class="p-3.5 font-bold text-foreground">{{ $pa->room->building->name ?? '' }}</td>
                                <td class="p-3.5">{{ $pa->room->room_number ?? '' }} ({{ $pa->bed->bed_number ?? '' }})</td>
                                <td class="p-3.5">{{ $pa->start_date->format('d M Y') }}</td>
                                <td class="p-3.5 font-semibold text-foreground">{{ $pa->actual_vacated_date?->format('d M Y') ?? 'N/A' }}</td>
                                <td class="p-3.5">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-secondary text-muted-foreground">
                                        {{ $pa->status }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Hostel Guidelines Notice Card -->
    @if ($this->rulesNotice)
        <div class="p-4 rounded-xl border border-border bg-secondary/20 flex items-start gap-3">
            <x-icon name="info" class="w-5 h-5 text-primary shrink-0 mt-0.5" />
            <div class="space-y-1">
                <h4 class="text-xs font-bold text-foreground uppercase tracking-wider">{{ __('Hostel Guidelines & Code of Conduct') }}</h4>
                <p class="text-xs text-muted-foreground whitespace-pre-line">{{ $this->rulesNotice }}</p>
            </div>
        </div>
    @endif

    <!-- Modal: Request Stay Extension -->
    <x-ui.modal name="student-extension-modal" max-width="max-w-md" :title="__('Request Accommodation Stay Extension')">
        <form wire:submit="submitExtensionRequest" class="space-y-4">
            <p class="text-xs text-muted-foreground">
                {{ __('Submit an extension request to the hostel office. Once submitted, administration will review and approve or reject your request.') }}
            </p>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Extension Duration Mode') }} *</label>
                <select
                    wire:model.live="extensionType"
                    class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option value="months">{{ __('Duration in Months') }}</option>
                    <option value="days">{{ __('Duration in Days') }}</option>
                    <option value="custom">{{ __('Specific End Date') }}</option>
                </select>
            </div>

            @if (in_array($extensionType, ['months', 'days'], true))
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">
                        {{ __('Additional') }} {{ ucfirst($extensionType) }} *
                    </label>
                    <input
                        type="number"
                        wire:model="extensionValue"
                        min="1"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    />
                </div>
            @elseif ($extensionType === 'custom')
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Requested New End Date') }} *</label>
                    <input
                        type="date"
                        wire:model="extensionCustomDate"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    />
                    @error('extensionCustomDate') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            @endif

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">{{ __('Reason for Extension Request') }} *</label>
                <textarea
                    wire:model="extensionReason"
                    rows="4"
                    placeholder="{{ __('State academic schedule, upcoming civil services examination dates, or specific justification for extending your hostel stay...') }}"
                    class="w-full p-2.5 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    required
                ></textarea>
                @error('extensionReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$store.modals.close('student-extension-modal')"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    variant="default"
                    size="sm"
                    class="bg-primary text-primary-foreground"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="submitExtensionRequest">{{ __('Submit Request') }}</span>
                    <span wire:loading wire:target="submitExtensionRequest">{{ __('Submitting...') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
