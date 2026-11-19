<?php

use App\Models\AuditLog;
use App\Models\ProfileUpdateRequest;
use App\Models\StudentAddress;
use App\Models\StudentDocument;
use App\Models\StudentEducation;
use App\Models\StudentEmployment;
use App\Models\StudentOtherDetail;
use App\Models\StudentProfile;
use App\Models\UpscAttempt;
use App\Models\User;
use App\Services\UserStudentSyncService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Profile Correction Requests')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'pending';

    #[Url(as: 'section')]
    public string $sectionFilter = '';

    public int $perPage = 10;

    // Review Modal state
    public ?int $reviewingRequestId = null;
    public ?ProfileUpdateRequest $reviewingRequest = null;
    public string $adminNotes = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSectionFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => ProfileUpdateRequest::count(),
            'pending' => ProfileUpdateRequest::where('status', 'pending')->count(),
            'approved' => ProfileUpdateRequest::where('status', 'approved')->count(),
            'rejected' => ProfileUpdateRequest::where('status', 'rejected')->count(),
        ];
    }

    #[Computed]
    public function requests()
    {
        return ProfileUpdateRequest::query()
            ->with(['user.studentProfile', 'reviewer'])
            ->when($this->search, function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($q) use ($term) {
                    $q->where('request_no', 'like', $term)
                        ->orWhere('reason', 'like', $term)
                        ->orWhereHas('user', function ($uq) use ($term) {
                            $uq->where('name', 'like', $term)
                                ->orWhere('email', 'like', $term)
                                ->orWhere('student_id', 'like', $term);
                        });
                });
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->sectionFilter, fn ($q) => $q->where('section', $this->sectionFilter))
            ->latest()
            ->paginate($this->perPage);
    }

    public function openReview(int $id): void
    {
        $this->reviewingRequestId = $id;
        $this->reviewingRequest = ProfileUpdateRequest::with([
            'user.studentProfile',
            'user.studentAddresses',
            'user.studentEducations',
            'user.studentEmployments',
            'user.studentOtherDetails',
            'user.upscAttempts',
            'user.studentDocuments',
        ])->findOrFail($id);
        $this->adminNotes = $this->reviewingRequest->admin_notes ?? '';
        $this->dispatch('modal-open', name: 'review-modal');
    }

    /**
     * Sanitize request payload data, converting empty strings to null for nullable, date, and numeric columns.
     *
     * @param array<string, mixed> $data
     * @param array<int, string> $dateFields
     * @param array<int, string> $numericFields
     * @return array<string, mixed>
     */
    protected function sanitizePayload(array $data, array $dateFields = [], array $numericFields = []): array
    {
        $cleaned = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    $value = null;
                }
            }

            if (in_array($key, $dateFields, true)) {
                if (empty($value)) {
                    $value = null;
                } elseif ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d');
                } else {
                    try {
                        $value = Carbon::parse($value)->format('Y-m-d');
                    } catch (\Throwable) {
                        $value = null;
                    }
                }
            } elseif (in_array($key, $numericFields, true)) {
                if (is_null($value) || $value === '') {
                    $value = null;
                }
            }

            $cleaned[$key] = $value;
        }

        return $cleaned;
    }

    public function approveRequest(): void
    {
        if (! $this->reviewingRequestId) {
            return;
        }

        $request = ProfileUpdateRequest::with('user')->findOrFail($this->reviewingRequestId);
        if ($request->status !== 'pending') {
            Toast::dispatch($this, 'error', __('This request has already been processed.'));
            return;
        }

        DB::beginTransaction();
        try {
            $user = $request->user;
            $payload = $request->payload ?: [];

            // 1. Account & Identity / Basic
            if (in_array($request->section, ['basic', 'account'], true)) {
                $userUpdates = array_intersect_key($payload, array_flip([
                    'name', 'first_name', 'last_name', 'phone', 'whatsapp_no', 'dob', 'gender', 'tenth_roll', 'id_type', 'id_number',
                ]));
                $userUpdates = $this->sanitizePayload($userUpdates, dateFields: ['dob']);
                if (! empty($userUpdates)) {
                    $user->update($userUpdates);
                }

                $profileUpdates = array_intersect_key($payload, array_flip([
                    'father_name', 'mother_name', 'category', 'distance', 'subcaste', 'creamy_layer', 'cert_no', 'issued_date', 'issued_by',
                    'is_pwbd', 'pwbd_type', 'pwd_percentage', 'pwbd_cert_no', 'pwbd_issued_date', 'pwbd_issued_by',
                    'occupation', 'father_occupation', 'mother_occupation', 'family_income', 'highest_edu', 'school_language',
                ]));
                $profileUpdates = $this->sanitizePayload($profileUpdates, dateFields: ['issued_date', 'pwbd_issued_date'], numericFields: ['distance', 'family_income', 'pwd_percentage']);
                if (! empty($profileUpdates)) {
                    StudentProfile::updateOrCreate(['user_id' => $user->id], $profileUpdates);
                }

                UserStudentSyncService::syncFromUser($user);
            }
            // 2. Profile & Family
            elseif ($request->section === 'profile') {
                $profileUpdates = array_intersect_key($payload, array_flip([
                    'father_name', 'mother_name', 'category', 'distance', 'subcaste', 'creamy_layer', 'cert_no', 'issued_date', 'issued_by',
                    'is_pwbd', 'pwbd_type', 'pwd_percentage', 'pwbd_cert_no', 'pwbd_issued_date', 'pwbd_issued_by',
                    'occupation', 'is_working_professional', 'father_occupation', 'mother_occupation', 'family_income', 'highest_edu', 'school_language',
                    'upsc_community', 'activities', 'hobbies',
                ]));
                $profileUpdates = $this->sanitizePayload($profileUpdates, dateFields: ['issued_date', 'pwbd_issued_date'], numericFields: ['distance', 'family_income', 'pwd_percentage']);
                if (! empty($profileUpdates)) {
                    StudentProfile::updateOrCreate(['user_id' => $user->id], $profileUpdates);
                }
            }
            // 3. Addresses
            elseif ($request->section === 'address') {
                // Check if dual permanent / correspondence payload
                if (isset($payload['permanent_state']) || isset($payload['permanent_address_line1'])) {
                    $permData = [];
                    foreach (['state', 'district', 'subdistrict', 'address_line1', 'address_line2', 'post_office', 'police_station', 'pin_code'] as $f) {
                        if (array_key_exists('permanent_'.$f, $payload)) {
                            $permData[$f] = $payload['permanent_'.$f];
                        }
                    }
                    $permData = $this->sanitizePayload($permData);
                    if (! empty($permData)) {
                        StudentAddress::updateOrCreate(['user_id' => $user->id, 'address_type' => 'Permanent'], $permData);
                    }
                }
                if (isset($payload['correspondence_state']) || isset($payload['correspondence_address_line1'])) {
                    $corrData = [];
                    foreach (['state', 'district', 'subdistrict', 'address_line1', 'address_line2', 'post_office', 'police_station', 'pin_code'] as $f) {
                        if (array_key_exists('correspondence_'.$f, $payload)) {
                            $corrData[$f] = $payload['correspondence_'.$f];
                        }
                    }
                    $corrData = $this->sanitizePayload($corrData);
                    if (! empty($corrData)) {
                        StudentAddress::updateOrCreate(['user_id' => $user->id, 'address_type' => 'Correspondence'], $corrData);
                    }
                }

                // Also support single address payload format
                $singleAddress = array_intersect_key($payload, array_flip([
                    'state', 'district', 'subdistrict', 'address_line1', 'address_line2', 'post_office', 'police_station', 'pin_code',
                ]));
                $singleAddress = $this->sanitizePayload($singleAddress);
                if (! empty($singleAddress)) {
                    $addressType = $payload['address_type'] ?? 'Permanent';
                    StudentAddress::updateOrCreate(['user_id' => $user->id, 'address_type' => $addressType], $singleAddress);
                }
            }
            // 4. Qualifications / Education
            elseif (in_array($request->section, ['education', 'qualifications'], true)) {
                $eduData = array_intersect_key($payload, array_flip([
                    'level', 'board_university', 'institute', 'stream_subject', 'passing_year', 'total_marks', 'marks_obtained', 'percentage', 'cgpa', 'division', 'completion_status', 'is_highest',
                ]));
                $eduData = $this->sanitizePayload($eduData, numericFields: ['passing_year', 'total_marks', 'marks_obtained', 'percentage', 'cgpa']);
                if (! empty($payload['education_id'])) {
                    StudentEducation::where('id', $payload['education_id'])->where('user_id', $user->id)->update($eduData);
                } elseif (! empty($eduData)) {
                    $eduData['user_id'] = $user->id;
                    StudentEducation::create($eduData);
                }
            }
            // 5. Employment
            elseif ($request->section === 'employment') {
                if (isset($payload['is_working_professional'])) {
                    StudentProfile::updateOrCreate(['user_id' => $user->id], [
                        'is_working_professional' => (bool) $payload['is_working_professional'],
                    ]);
                }

                $empData = array_intersect_key($payload, array_flip([
                    'employer_name', 'designation', 'nature_of_employment', 'location', 'from_date', 'to_date', 'is_current', 'description',
                ]));
                $empData = $this->sanitizePayload($empData, dateFields: ['from_date', 'to_date']);
                if (! empty($payload['employment_id'])) {
                    StudentEmployment::where('id', $payload['employment_id'])->where('user_id', $user->id)->update($empData);
                } elseif (! empty($empData)) {
                    $empData['user_id'] = $user->id;
                    StudentEmployment::create($empData);
                }
            }
            // 6. UPSC Details
            elseif (in_array($request->section, ['upsc', 'other'], true)) {
                $otherData = array_intersect_key($payload, array_flip([
                    'optional_subject', 'attempts_already_given', 'attempts_left', 'year_of_appearing', 'activities', 'hobbies',
                ]));
                $otherData = $this->sanitizePayload($otherData, numericFields: ['attempts_already_given', 'attempts_left', 'year_of_appearing']);
                if (! empty($otherData)) {
                    StudentOtherDetail::updateOrCreate(['user_id' => $user->id], $otherData);
                }

                if (isset($payload['exam_year']) || isset($payload['roll_number'])) {
                    $attemptData = array_intersect_key($payload, array_flip([
                        'exam_year', 'roll_number', 'prelims_cleared', 'mains_cleared', 'interview_cleared',
                    ]));
                    $attemptData = $this->sanitizePayload($attemptData, numericFields: ['exam_year']);
                    if (! empty($payload['attempt_id'])) {
                        UpscAttempt::where('id', $payload['attempt_id'])->where('user_id', $user->id)->update($attemptData);
                    } elseif (! empty($attemptData['exam_year'])) {
                        $attemptData['user_id'] = $user->id;
                        UpscAttempt::create($attemptData);
                    }
                }
            }
            // 7. Uploaded Documents
            elseif ($request->section === 'documents') {
                $docData = array_intersect_key($payload, array_flip([
                    'type', 'document_type', 'file_path', 'file_name', 'file_url', 'description',
                ]));
                $docData = $this->sanitizePayload($docData);
                $docData['user_id'] = $user->id;
                $docData['type'] = $docData['type'] ?? $docData['document_type'] ?? 'Other Supporting Document';
                $docData['verified'] = true;
                $docData['verified_by'] = auth()->id();
                $docData['verified_at'] = now();
                $docData['is_active'] = true;
                $docData['uploaded_at'] = now();
                StudentDocument::create($docData);
            }

            // Mark request approved
            $request->update([
                'status' => 'approved',
                'admin_notes' => $this->adminNotes ?: 'Approved by administrator.',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            // Audit log
            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'profile_update_approved',
                'auditable_type' => ProfileUpdateRequest::class,
                'auditable_id' => $request->id,
                'description' => "Approved profile correction request #{$request->request_no} for student {$user->name} ({$user->student_id})",
                'new_values' => $payload,
            ]);

            DB::commit();
            Toast::dispatch($this, 'success', __('Profile correction request approved and applied.'));
            $this->dispatch('modal-close', name: 'review-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Approval failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function rejectRequest(): void
    {
        if (! $this->reviewingRequestId) {
            return;
        }

        $request = ProfileUpdateRequest::with('user')->findOrFail($this->reviewingRequestId);
        if ($request->status !== 'pending') {
            Toast::dispatch($this, 'error', __('This request has already been processed.'));
            return;
        }

        if (empty(trim($this->adminNotes))) {
            Toast::dispatch($this, 'error', __('Please enter a rejection reason or admin note.'));
            return;
        }

        DB::beginTransaction();
        try {
            $request->update([
                'status' => 'rejected',
                'admin_notes' => $this->adminNotes,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'profile_update_rejected',
                'auditable_type' => ProfileUpdateRequest::class,
                'auditable_id' => $request->id,
                'description' => "Rejected profile correction request #{$request->request_no} for student {$request->user->name}",
                'new_values' => ['reason' => $this->adminNotes],
            ]);

            DB::commit();

            Toast::dispatch($this, 'success', __('Profile correction request rejected.'));
            $this->dispatch('modal-close', name: 'review-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            Toast::dispatch($this, 'error', __('Rejection failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'pending';
        $this->sectionFilter = '';
        $this->resetPage();
    }
}; ?>

<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                <x-icon name="edit" class="w-6 h-6 text-amber-600 dark:text-amber-400" />
                {{ __('Student Profile Correction Requests') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Strict verification workflow: Students can inspect their profile details but cannot modify them without administrator review and approval.') }}
            </p>
        </div>
    </div>

    <!-- Stats Overview Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-card border border-border rounded-xl p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Total Requests') }}</span>
                <span class="p-2 rounded-lg bg-muted text-muted-foreground">
                    <x-icon name="file-text" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-foreground mt-2">{{ $this->stats['total'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('All-time submissions') }}</div>
        </div>

        <div class="bg-card border border-border rounded-xl p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider">{{ __('Pending Review') }}</span>
                <span class="p-2 rounded-lg bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400">
                    <x-icon name="clock" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-2">{{ $this->stats['pending'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Awaiting admin decision') }}</div>
        </div>

        <div class="bg-card border border-border rounded-xl p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">{{ __('Approved') }}</span>
                <span class="p-2 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="check-circle" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-2">{{ $this->stats['approved'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Verified and applied') }}</div>
        </div>

        <div class="bg-card border border-border rounded-xl p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-rose-600 dark:text-rose-400 uppercase tracking-wider">{{ __('Rejected') }}</span>
                <span class="p-2 rounded-lg bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400">
                    <x-icon name="x-circle" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-rose-600 dark:text-rose-400 mt-2">{{ $this->stats['rejected'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Declined with notes') }}</div>
        </div>
    </div>

    <!-- Filters Toolbar -->
    <div class="bg-card border border-border rounded-xl p-4 shadow-xs">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            <div class="relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search request #, student name, ID...') }}"
                    class="h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-8 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                @if ($search)
                    <button type="button" wire:click="$set('search', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                    </button>
                @endif
            </div>

            <div>
                <select wire:model.live="statusFilter" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                    <option value="">{{ __('All Statuses') }}</option>
                    <option value="pending">{{ __('Pending Review') }}</option>
                    <option value="approved">{{ __('Approved') }}</option>
                    <option value="rejected">{{ __('Rejected') }}</option>
                </select>
            </div>

            <div>
                <select wire:model.live="sectionFilter" class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                    <option value="">{{ __('All Sections') }}</option>
                    <option value="account">{{ __('1. Account & Identity') }}</option>
                    <option value="profile">{{ __('2. Profile & Family') }}</option>
                    <option value="address">{{ __('3. Addresses') }}</option>
                    <option value="education">{{ __('4. Qualifications') }}</option>
                    <option value="employment">{{ __('5. Employment') }}</option>
                    <option value="upsc">{{ __('6. UPSC Details') }}</option>
                    <option value="documents">{{ __('7. Uploaded Documents') }}</option>
                    <option value="basic">{{ __('Basic (Legacy)') }}</option>
                </select>
            </div>

            <div class="flex items-center justify-end">
                @if($search || $statusFilter !== 'pending' || $sectionFilter)
                    <x-ui.button variant="ghost" size="sm" wire:click="resetFilters" class="gap-1.5 text-xs text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="w-3.5 h-3.5" />
                        {{ __('Reset Filters') }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    </div>

    <!-- Requests Table -->
    <div class="bg-card border border-border rounded-xl shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead class="bg-muted/50 border-b border-border text-xs text-muted-foreground uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('Request # & Date') }}</th>
                        <th class="px-4 py-3">{{ __('Student (Candidate)') }}</th>
                        <th class="px-4 py-3">{{ __('Section') }}</th>
                        <th class="px-4 py-3">{{ __('Student Justification') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Reviewed By') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-foreground">
                    @forelse($this->requests as $req)
                        <tr class="hover:bg-muted/40 transition-colors">
                            <td class="px-4 py-3">
                                <span class="font-mono font-bold text-xs text-foreground">
                                    {{ $req->request_no }}
                                </span>
                                <div class="text-[10px] text-muted-foreground">
                                    {{ $req->created_at->format('d M Y, h:i A') }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-semibold text-foreground">
                                    {{ $req->user?->name }}
                                </div>
                                <div class="font-mono text-xs text-primary font-medium">
                                    ID: {{ $req->user?->student_id ?? 'N/A' }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge variant="secondary" class="capitalize text-[11px]">
                                    {{ $req->section }}
                                </x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-xs text-muted-foreground max-w-xs truncate">
                                {{ $req->reason }}
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $statusVariant = match($req->status) {
                                        'approved' => 'success',
                                        'rejected' => 'destructive',
                                        default => 'warning',
                                    };
                                @endphp
                                <x-ui.badge :variant="$statusVariant" class="capitalize text-[11px]">
                                    {{ ucfirst($req->status) }}
                                </x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if($req->reviewer)
                                    <div class="font-semibold">{{ $req->reviewer->name }}</div>
                                    <div class="text-[10px] text-muted-foreground">{{ $req->reviewed_at?->format('d M Y') }}</div>
                                @else
                                    <span class="text-muted-foreground italic">{{ __('Unassigned') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <x-ui.button size="xs" variant="default" wire:click="openReview({{ $req->id }})" class="gap-1 text-xs">
                                    <x-icon name="eye" class="w-3.5 h-3.5" />
                                    {{ $req->status === 'pending' ? __('Review & Decision') : __('View Diff') }}
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="check-circle" class="w-8 h-8 text-emerald-500" />
                                    <span class="text-base font-medium">{{ __('No correction requests in this view') }}</span>
                                    <span class="text-xs text-muted-foreground">{{ __('All candidate profile records are up to date.') }}</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-border">
            {{ $this->requests->links() }}
        </div>
    </div>

    <!-- Review & Diff Inspector Modal -->
    <x-ui.modal name="review-modal" max-width="max-w-3xl" :title="__('Review Profile Change Request')" :description="__('Inspect candidate submitted changes and verify before approving or rejecting.')">
        @if($reviewingRequest)
            <div class="space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 p-3 bg-muted/40 rounded-lg border border-border">
                    <div>
                        <div class="font-semibold text-sm text-foreground flex items-center gap-2">
                            <span>{{ $reviewingRequest->user?->name }}</span>
                            <x-ui.badge variant="warning" class="text-[10px] font-mono">{{ $reviewingRequest->request_no }}</x-ui.badge>
                        </div>
                        <div class="text-xs text-muted-foreground">
                            {{ __('Student ID: :id • Submitted on :date', [
                                'id' => $reviewingRequest->user?->student_id ?? 'N/A',
                                'date' => $reviewingRequest->created_at->format('d M Y, h:i A')
                            ]) }}
                        </div>
                    </div>
                    <x-ui.badge variant="secondary" class="capitalize self-start sm:self-auto text-xs">
                        {{ $reviewingRequest->section }} {{ __('Section') }}
                    </x-ui.badge>
                </div>

                <!-- Justification Box -->
                <div class="p-3 bg-muted/30 rounded-lg text-xs space-y-1 border border-border">
                    <span class="font-semibold text-foreground">{{ __('Student Stated Reason / Justification:') }}</span>
                    <p class="text-muted-foreground leading-relaxed">{{ $reviewingRequest->reason }}</p>
                </div>

                @if($reviewingRequest->document_proof)
                    <div class="p-3 bg-muted/40 rounded-lg text-xs flex items-center justify-between border border-border">
                        <div class="flex items-center gap-2">
                            <x-icon name="paperclip" class="w-4 h-4 text-primary" />
                            <span class="font-medium text-foreground">{{ __('Supporting Document / Proof Attached') }}</span>
                        </div>
                        <a href="{{ Storage::url($reviewingRequest->document_proof) }}" target="_blank" class="inline-flex items-center gap-1 text-primary hover:underline font-semibold">
                            <x-icon name="external-link" class="w-3.5 h-3.5" />
                            {{ __('View / Download Proof') }}
                        </a>
                    </div>
                @endif

                <!-- Side-by-Side Diff Inspector Table -->
                <div>
                    <h4 class="text-xs font-semibold uppercase text-muted-foreground mb-2">{{ __('Proposed Changes Diff') }}</h4>
                    <div class="border border-border rounded-lg overflow-hidden text-xs">
                        <table class="w-full text-left">
                            <thead class="bg-muted/50 border-b border-border">
                                <tr>
                                    <th class="p-2.5 w-1/3 text-muted-foreground">{{ __('Field Name') }}</th>
                                    <th class="p-2.5 w-1/3 text-rose-600 dark:text-rose-400">{{ __('Current Value') }}</th>
                                    <th class="p-2.5 w-1/3 text-emerald-600 dark:text-emerald-400">{{ __('Requested Value') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($reviewingRequest->payload as $field => $newValue)
                                    @php
                                        $oldValue = $reviewingRequest->current_data[$field] ?? '—';
                                    @endphp
                                    <tr>
                                        <td class="p-2.5 font-semibold capitalize text-foreground">
                                             {{ str_replace('_', ' ', $field) }}
                                        </td>
                                        <td class="p-2.5 bg-rose-50/50 dark:bg-rose-950/20 text-rose-700 dark:text-rose-400">
                                            {{ is_array($oldValue) ? json_encode($oldValue) : ($oldValue ?: '—') }}
                                        </td>
                                        <td class="p-2.5 bg-emerald-50/50 dark:bg-emerald-950/20 text-emerald-700 dark:text-emerald-400 font-semibold">
                                            {{ is_array($newValue) ? json_encode($newValue) : ($newValue ?: '—') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="p-4 text-center text-muted-foreground">{{ __('No field changes detected.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Admin Notes -->
                <div class="space-y-1.5">
                    <label class="text-xs font-medium text-foreground">{{ __('Administrator Notes / Decision Reason') }}</label>
                    <textarea
                        wire:model="adminNotes"
                        rows="3"
                        placeholder="{{ __('Required for rejection; optional for approval...') }}"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    ></textarea>
                </div>

                <div class="flex items-center justify-between pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('review-modal')">
                        {{ __('Close') }}
                    </x-ui.button>

                    @if($reviewingRequest->status === 'pending')
                        <div class="flex items-center gap-2">
                            <x-ui.button variant="destructive" type="button" wire:click="rejectRequest" class="gap-1">
                                <x-icon name="x" class="w-3.5 h-3.5" />
                                {{ __('Reject Request') }}
                            </x-ui.button>
                            <x-ui.button variant="default" type="button" wire:click="approveRequest" class="gap-1 bg-emerald-600 hover:bg-emerald-700 text-white">
                                <x-icon name="check-circle" class="w-3.5 h-3.5" />
                                {{ __('Approve & Apply Changes') }}
                            </x-ui.button>
                        </div>
                    @else
                        <div class="text-xs text-muted-foreground">
                            {{ __('Decision recorded as :status', ['status' => ucfirst($reviewingRequest->status)]) }}
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
