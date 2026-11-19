<?php

namespace App\Services\Hostel;

use App\Models\AuditLog;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelExtensionRequest;
use App\Models\HostelRoom;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HostelService
{
    /**
     * Generate unique allocation number (e.g. HST-2026-0001).
     */
    public static function generateAllocationNumber(): string
    {
        $year = now()->format('Y');
        $prefix = "HST-{$year}-";

        $last = HostelAllocation::withTrashed()
            ->where('allocation_no', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('allocation_no');

        if (! $last) {
            return $prefix.'0001';
        }

        $lastSeq = (int) str_replace($prefix, '', $last);

        return $prefix.str_pad((string) ($lastSeq + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generate unique extension request number (e.g. HER-2026-0001).
     */
    public static function generateRequestNumber(): string
    {
        $year = now()->format('Y');
        $prefix = "HER-{$year}-";

        $last = HostelExtensionRequest::withTrashed()
            ->where('request_no', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('request_no');

        if (! $last) {
            return $prefix.'0001';
        }

        $lastSeq = (int) str_replace($prefix, '', $last);

        return $prefix.str_pad((string) ($lastSeq + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Create a new room & bed allocation for a student or staff resident.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function allocateResident(array $data, ?int $createdBy = null): HostelAllocation
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $user = User::findOrFail($data['user_id']);
            $bed = HostelBed::with('room.building')->findOrFail($data['hostel_bed_id']);
            $room = $bed->room;

            // Check if bed is available
            if ($bed->status !== 'available') {
                throw ValidationException::withMessages([
                    'hostel_bed_id' => __('Selected bed (:bed) is currently :status and cannot be assigned.', [
                        'bed' => $bed->bed_number,
                        'status' => ucfirst($bed->status),
                    ]),
                ]);
            }

            // Check if user already has an active allocation
            $existing = HostelAllocation::active()->where('user_id', $user->id)->first();
            if ($existing) {
                throw ValidationException::withMessages([
                    'user_id' => __('User :name already holds an active hostel accommodation in Room :room (Bed :bed). Please vacate the current room first.', [
                        'name' => $user->name,
                        'room' => $existing->room->room_number ?? 'N/A',
                        'bed' => $existing->bed->bed_number ?? 'N/A',
                    ]),
                ]);
            }

            // Enforce Gender Restrictions
            self::validateGenderEligibility($user, $room);

            // Compute dates & timeline
            $startDate = Carbon::parse($data['start_date'] ?? now()->toDateString());
            $durationType = $data['duration_type'] ?? ($data['duration_unit'] ?? 'indefinite');
            $durationValue = ! empty($data['duration_value']) ? (int) $data['duration_value'] : null;
            $endDate = null;

            if ($durationType === 'custom') {
                $endDate = ! empty($data['end_date']) ? Carbon::parse($data['end_date']) : null;
            } elseif ($durationType !== 'indefinite' && $durationValue) {
                $endDate = match ($durationType) {
                    'days' => $startDate->copy()->addDays($durationValue),
                    'months' => $startDate->copy()->addMonths($durationValue),
                    'years' => $startDate->copy()->addYears($durationValue),
                    default => null,
                };
            }

            $residentType = $data['resident_type'] ?? ($user->isStudent() ? 'student' : 'staff');

            // Hard copy form tracking
            $hardCopySubmitted = (bool) ($data['hard_copy_submitted'] ?? ($data['hard_copy_form_submitted'] ?? false));
            $hardCopySubmittedAt = $hardCopySubmitted ? ($data['hard_copy_submitted_at'] ?? now()->toDateString()) : null;
            $hardCopyReceivedBy = $hardCopySubmitted ? ($data['hard_copy_received_by'] ?? $createdBy) : null;

            $isPreviousBatch = (bool) ($data['is_previous_batch_student'] ?? false);
            $previousBatchName = $data['previous_batch_name'] ?? ($data['previous_batch_identifier'] ?? null);

            $allocation = HostelAllocation::create([
                'allocation_no' => self::generateAllocationNumber(),
                'user_id' => $user->id,
                'resident_type' => $residentType,
                'hostel_room_id' => $room->id,
                'hostel_bed_id' => $bed->id,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate?->toDateString(),
                'duration_type' => $durationType,
                'duration_value' => $durationValue,
                'status' => 'active',
                'remarks' => $data['remarks'] ?? null,
                'hard_copy_submitted' => $hardCopySubmitted,
                'hard_copy_submitted_at' => $hardCopySubmittedAt,
                'hard_copy_received_by' => $hardCopyReceivedBy,
                'hard_copy_notes' => $data['hard_copy_notes'] ?? null,
                'soft_copy_path' => $data['soft_copy_path'] ?? null,
                'soft_copy_uploaded_at' => ! empty($data['soft_copy_path']) ? now() : null,
                'soft_copy_uploaded_by' => ! empty($data['soft_copy_path']) ? $createdBy : null,
                'soft_copy_verified' => (bool) ($data['soft_copy_verified'] ?? false),
                'soft_copy_verified_at' => ! empty($data['soft_copy_verified']) ? now() : null,
                'soft_copy_verified_by' => ! empty($data['soft_copy_verified']) ? $createdBy : null,
                'is_previous_batch_student' => $isPreviousBatch,
                'previous_batch_name' => $previousBatchName,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]);

            // Mark bed occupied
            $bed->update([
                'status' => 'occupied',
                'updated_by' => $createdBy,
            ]);

            // Sync room status
            self::syncRoomStatus($room);

            AuditLog::record(
                action: 'hostel_resident_allocated',
                details: [
                    'allocation_no' => $allocation->allocation_no,
                    'user_id' => $user->id,
                    'resident_name' => $user->name,
                    'room_number' => $room->room_number,
                    'bed_number' => $bed->bed_number,
                    'start_date' => $allocation->start_date?->toDateString(),
                    'end_date' => $allocation->end_date?->toDateString(),
                ],
                userId: $createdBy,
                auditableType: HostelAllocation::class,
                auditableId: $allocation->id,
                description: "Allocated Room {$room->room_number} Bed {$bed->bed_number} to {$user->name}"
            );

            return $allocation;
        });
    }

    /**
     * Validate that resident's gender aligns with the room's gender allowance.
     *
     * @throws ValidationException
     */
    public static function validateGenderEligibility(User $user, HostelRoom $room): void
    {
        if ($room->gender_allowed === 'any') {
            return;
        }

        $userGender = strtolower(trim((string) ($user->gender ?? $user->studentProfile?->gender ?? '')));

        if (empty($userGender)) {
            // Cannot reliably determine gender - warn if room has strict gender restriction
            return;
        }

        $isMale = in_array($userGender, ['male', 'm', 'boy'], true);
        $isFemale = in_array($userGender, ['female', 'f', 'girl', 'woman'], true);

        if ($room->gender_allowed === 'male_only' && ! $isMale) {
            throw ValidationException::withMessages([
                'hostel_room_id' => __('Room :room is designated for Male residents only. Selected resident (:name) is registered as :gender.', [
                    'room' => $room->room_number,
                    'name' => $user->name,
                    'gender' => ucfirst($userGender),
                ]),
            ]);
        }

        if ($room->gender_allowed === 'female_only' && ! $isFemale) {
            throw ValidationException::withMessages([
                'hostel_room_id' => __('Room :room is designated for Female residents only. Selected resident (:name) is registered as :gender.', [
                    'room' => $room->room_number,
                    'name' => $user->name,
                    'gender' => ucfirst($userGender),
                ]),
            ]);
        }
    }

    /**
     * Explicitly extend an existing room accommodation timeline.
     */
    public static function extendAllocation(HostelAllocation $allocation, string $newEndDate, ?string $notes = null, ?int $updatedBy = null): HostelAllocation
    {
        return DB::transaction(function () use ($allocation, $newEndDate, $notes, $updatedBy) {
            $parsedDate = Carbon::parse($newEndDate);
            $currentEndDate = $allocation->end_date;

            $remarks = $allocation->remarks ?? '';
            if ($notes) {
                $remarks .= "\n[Extended on ".now()->format('d M Y')." by Admin: {$notes}]";
            }

            $allocation->update([
                'end_date' => $parsedDate->toDateString(),
                'status' => 'extended',
                'remarks' => trim($remarks),
                'updated_by' => $updatedBy,
            ]);

            // Create approved extension request record for audit history
            HostelExtensionRequest::create([
                'request_no' => self::generateRequestNumber(),
                'hostel_allocation_id' => $allocation->id,
                'user_id' => $allocation->user_id,
                'current_end_date' => $currentEndDate?->toDateString(),
                'requested_end_date' => $parsedDate->toDateString(),
                'extension_duration_type' => 'custom',
                'extension_duration_value' => $currentEndDate ? max(1, (int) $currentEndDate->diffInDays($parsedDate)) : 30,
                'reason' => $notes ?? __('Admin direct stay extension'),
                'status' => 'approved',
                'admin_notes' => $notes ?? __('Direct extension granted by administrator.'),
                'reviewed_by' => $updatedBy,
                'reviewed_at' => now(),
                'created_by' => $updatedBy,
            ]);

            AuditLog::record(
                action: 'hostel_allocation_extended',
                details: [
                    'allocation_no' => $allocation->allocation_no,
                    'user_id' => $allocation->user_id,
                    'resident_name' => $allocation->user?->name,
                    'previous_end_date' => $currentEndDate?->toDateString(),
                    'new_end_date' => $parsedDate->toDateString(),
                    'notes' => $notes,
                ],
                userId: $updatedBy,
                auditableType: HostelAllocation::class,
                auditableId: $allocation->id,
                description: "Extended stay for {$allocation->user?->name} until {$parsedDate->toDateString()}"
            );

            return $allocation->refresh();
        });
    }

    /**
     * Vacate a resident from their room & bed.
     */
    public static function vacateAllocation(HostelAllocation $allocation, ?string $vacatedDateOrRemarks = null, ?string $remarks = null, ?int $updatedBy = null): HostelAllocation
    {
        return DB::transaction(function () use ($allocation, $vacatedDateOrRemarks, $remarks, $updatedBy) {
            $vacatedDate = null;
            if ($vacatedDateOrRemarks) {
                try {
                    $vacatedDate = Carbon::parse($vacatedDateOrRemarks);
                } catch (\Throwable) {
                    $remarks = $vacatedDateOrRemarks;
                    $vacatedDate = now();
                }
            } else {
                $vacatedDate = now();
            }

            $existingRemarks = $allocation->remarks ?? '';
            if ($remarks) {
                $existingRemarks .= "\n[Vacated on {$vacatedDate->format('d M Y')}: {$remarks}]";
            }

            $allocation->update([
                'status' => 'checked_out',
                'actual_vacated_date' => $vacatedDate->toDateString(),
                'remarks' => trim($existingRemarks),
                'updated_by' => $updatedBy,
            ]);

            // Free the bed
            $bed = $allocation->bed;
            if ($bed) {
                $bed->update([
                    'status' => 'available',
                    'updated_by' => $updatedBy,
                ]);

                // Sync parent room status
                if ($bed->room) {
                    self::syncRoomStatus($bed->room);
                }
            }

            AuditLog::record(
                action: 'hostel_resident_vacated',
                details: [
                    'allocation_no' => $allocation->allocation_no,
                    'user_id' => $allocation->user_id,
                    'resident_name' => $allocation->user?->name,
                    'vacated_date' => $vacatedDate->toDateString(),
                    'remarks' => $remarks,
                ],
                userId: $updatedBy,
                auditableType: HostelAllocation::class,
                auditableId: $allocation->id,
                description: "Vacated resident {$allocation->user?->name} on {$vacatedDate->toDateString()}"
            );

            return $allocation->refresh();
        });
    }

    /**
     * Cancel an allocation.
     */
    public static function cancelAllocation(HostelAllocation $allocation, ?string $reason = null, ?int $updatedBy = null): HostelAllocation
    {
        return DB::transaction(function () use ($allocation, $reason, $updatedBy) {
            $existingRemarks = $allocation->remarks ?? '';
            if ($reason) {
                $existingRemarks .= "\n[Cancelled on ".now()->format('d M Y').": {$reason}]";
            }

            $allocation->update([
                'status' => 'cancelled',
                'remarks' => trim($existingRemarks),
                'updated_by' => $updatedBy,
            ]);

            // Free bed
            if ($allocation->bed) {
                $allocation->bed->update([
                    'status' => 'available',
                    'updated_by' => $updatedBy,
                ]);

                if ($allocation->bed->room) {
                    self::syncRoomStatus($allocation->bed->room);
                }
            }

            AuditLog::record(
                action: 'hostel_allocation_cancelled',
                details: [
                    'allocation_no' => $allocation->allocation_no,
                    'user_id' => $allocation->user_id,
                    'resident_name' => $allocation->user?->name,
                    'reason' => $reason,
                ],
                userId: $updatedBy,
                auditableType: HostelAllocation::class,
                auditableId: $allocation->id,
                description: "Cancelled allocation {$allocation->allocation_no} for {$allocation->user?->name}"
            );

            return $allocation->refresh();
        });
    }

    /**
     * Submit an extension request from the resident dashboard.
     *
     * @throws ValidationException
     */
    public static function submitExtensionRequest(
        HostelAllocation $allocation,
        string $requestedEndDate,
        string $durationTypeOrReason = 'custom',
        int|string|null $durationValue = null,
        ?string $reason = null,
        ?int $userId = null,
    ): HostelExtensionRequest {
        if (! self::isExtensionRequestAllowed()) {
            throw ValidationException::withMessages([
                'reason' => __('Accommodation extension requests are currently disabled by hostel administration.'),
            ]);
        }

        // Determine if 3rd argument is actually the reason (shorthand call)
        if (! in_array($durationTypeOrReason, ['days', 'months', 'years', 'custom'], true)) {
            $reason = $durationTypeOrReason;
            $durationType = 'custom';
            $durationValue = 1;
        } else {
            $durationType = $durationTypeOrReason;
            $durationValue = is_numeric($durationValue) ? (int) $durationValue : 1;
        }

        $userId = $userId ?? $allocation->user_id;

        // Check if pending request exists
        $hasPending = $allocation->extensionRequests()->pending()->exists();
        if ($hasPending) {
            throw ValidationException::withMessages([
                'reason' => __('You already have a pending extension request under review by the hostel office.'),
            ]);
        }

        return HostelExtensionRequest::create([
            'request_no' => self::generateRequestNumber(),
            'hostel_allocation_id' => $allocation->id,
            'user_id' => $userId,
            'current_end_date' => $allocation->end_date?->toDateString(),
            'requested_end_date' => Carbon::parse($requestedEndDate)->toDateString(),
            'extension_duration_type' => $durationType,
            'extension_duration_value' => $durationValue,
            'reason' => $reason ?? __('Stay extension requested by resident.'),
            'status' => 'pending',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    /**
     * Approve resident extension request.
     */
    public static function approveExtensionRequest(
        HostelExtensionRequest $request,
        User|int|string|null $param1 = null,
        User|int|string|null $param2 = null,
    ): HostelExtensionRequest {
        $adminNotes = null;
        $reviewedBy = null;

        if ($param1 instanceof User) {
            $reviewedBy = $param1->id;
            $adminNotes = is_string($param2) ? $param2 : null;
        } elseif (is_int($param1)) {
            $reviewedBy = $param1;
            $adminNotes = is_string($param2) ? $param2 : null;
        } elseif (is_string($param1)) {
            $adminNotes = $param1;
            $reviewedBy = $param2 instanceof User ? $param2->id : (is_numeric($param2) ? (int) $param2 : auth()->id());
        } else {
            $reviewedBy = $param2 instanceof User ? $param2->id : (is_numeric($param2) ? (int) $param2 : auth()->id());
        }

        return DB::transaction(function () use ($request, $adminNotes, $reviewedBy) {
            $request->update([
                'status' => 'approved',
                'admin_notes' => $adminNotes,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
                'updated_by' => $reviewedBy,
            ]);

            // Extend allocation
            $allocation = $request->allocation;
            if ($allocation && $allocation->isActive()) {
                self::extendAllocation(
                    $allocation,
                    $request->requested_end_date->toDateString(),
                    $adminNotes ? "Extension Request #{$request->request_no} Approved: {$adminNotes}" : "Extension Request #{$request->request_no} Approved",
                    $reviewedBy
                );
            }

            return $request->refresh();
        });
    }

    /**
     * Reject resident extension request with mandatory reason.
     *
     * @throws ValidationException
     */
    public static function rejectExtensionRequest(
        HostelExtensionRequest $request,
        User|int|string|null $param1 = null,
        User|int|string|null $param2 = null,
    ): HostelExtensionRequest {
        $adminNotes = null;
        $reviewedBy = null;

        if ($param1 instanceof User || is_int($param1)) {
            $reviewedBy = $param1 instanceof User ? $param1->id : $param1;
            $adminNotes = is_string($param2) ? $param2 : '';
        } else {
            $adminNotes = is_string($param1) ? $param1 : '';
            $reviewedBy = $param2 instanceof User ? $param2->id : (is_numeric($param2) ? (int) $param2 : auth()->id());
        }

        if (empty(trim((string) $adminNotes))) {
            throw ValidationException::withMessages([
                'admin_notes' => __('A rejection reason is required when denying an accommodation extension request.'),
            ]);
        }

        $request->update([
            'status' => 'rejected',
            'admin_notes' => trim($adminNotes),
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
            'updated_by' => $reviewedBy,
        ]);

        AuditLog::record(
            action: 'hostel_extension_rejected',
            details: [
                'request_no' => $request->request_no,
                'allocation_id' => $request->hostel_allocation_id,
                'user_id' => $request->user_id,
                'admin_notes' => $adminNotes,
            ],
            userId: $reviewedBy,
            auditableType: HostelExtensionRequest::class,
            auditableId: $request->id,
            description: "Rejected hostel extension request #{$request->request_no}: {$adminNotes}"
        );

        return $request->refresh();
    }

    /**
     * Transfer an existing resident to a new room & bed.
     *
     * @throws ValidationException
     */
    public static function transferResident(
        HostelAllocation $allocation,
        int $newBedId,
        ?string $transferDate = null,
        ?string $reason = null,
        ?int $updatedBy = null,
    ): HostelAllocation {
        return DB::transaction(function () use ($allocation, $newBedId, $transferDate, $reason, $updatedBy) {
            if (! in_array($allocation->status, ['active', 'extended', 'allocated'], true)) {
                throw ValidationException::withMessages([
                    'allocation' => __('Only active resident allocations can be transferred to a new room or bed.'),
                ]);
            }

            if ($allocation->hostel_bed_id === $newBedId) {
                throw ValidationException::withMessages([
                    'new_hostel_bed_id' => __('Resident is already assigned to the selected bed.'),
                ]);
            }

            $newBed = HostelBed::with('room.building')->find($newBedId);
            if (! $newBed) {
                throw ValidationException::withMessages([
                    'new_hostel_bed_id' => __('Selected target bed was not found.'),
                ]);
            }

            if ($newBed->status !== 'available') {
                throw ValidationException::withMessages([
                    'new_hostel_bed_id' => __('Target bed (:bed) is currently :status and unavailable for transfer.', [
                        'bed' => $newBed->bed_number,
                        'status' => ucfirst($newBed->status),
                    ]),
                ]);
            }

            $targetRoom = $newBed->room;
            if (! $targetRoom) {
                throw ValidationException::withMessages([
                    'new_hostel_bed_id' => __('Target bed does not have an associated room.'),
                ]);
            }

            // Gender restriction validation
            $user = $allocation->user ?? User::find($allocation->user_id);
            if ($user) {
                self::validateGenderEligibility($user, $targetRoom);
            }

            $oldBed = $allocation->bed;
            $oldRoom = $allocation->room;
            $parsedDate = $transferDate ? Carbon::parse($transferDate) : now();

            // 1. Release previous bed
            if ($oldBed) {
                $oldBed->update([
                    'status' => 'available',
                    'updated_by' => $updatedBy,
                ]);

                if ($oldRoom) {
                    self::syncRoomStatus($oldRoom);
                }
            }

            // 2. Format transfer remarks
            $transferNote = "[Transferred on {$parsedDate->format('d M Y')} from Room {$oldRoom?->room_number} (Bed {$oldBed?->bed_number}) to Room {$targetRoom->room_number} (Bed {$newBed->bed_number})";
            if ($reason) {
                $transferNote .= ". Reason: {$reason}";
            }
            $transferNote .= ']';

            $existingRemarks = trim((string) $allocation->remarks);
            $newRemarks = $existingRemarks ? "{$existingRemarks}\n{$transferNote}" : $transferNote;

            // 3. Update allocation record
            $allocation->update([
                'hostel_room_id' => $targetRoom->id,
                'hostel_bed_id' => $newBed->id,
                'remarks' => $newRemarks,
                'updated_by' => $updatedBy,
            ]);

            // 4. Occupy target bed
            $newBed->update([
                'status' => 'occupied',
                'updated_by' => $updatedBy,
            ]);

            self::syncRoomStatus($targetRoom);

            // 5. Audit log
            AuditLog::record(
                action: 'hostel_resident_transferred',
                details: [
                    'allocation_id' => $allocation->id,
                    'allocation_no' => $allocation->allocation_no,
                    'user_id' => $allocation->user_id,
                    'resident_name' => $user?->name,
                    'old_room' => $oldRoom?->room_number,
                    'old_bed' => $oldBed?->bed_number,
                    'new_room' => $targetRoom->room_number,
                    'new_bed' => $newBed->bed_number,
                    'transfer_date' => $parsedDate->toDateString(),
                    'reason' => $reason,
                ],
                userId: $updatedBy,
                auditableType: HostelAllocation::class,
                auditableId: $allocation->id,
                description: "Transferred resident {$user?->name} from Room {$oldRoom?->room_number} (Bed {$oldBed?->bed_number}) to Room {$targetRoom->room_number} (Bed {$newBed->bed_number})"
            );

            return $allocation->refresh();
        });
    }

    /**
     * Update bed operational status (e.g. available, maintenance, reserved).
     *
     * @throws ValidationException
     */
    public static function updateBedStatus(
        HostelBed $bed,
        string $status,
        ?string $notes = null,
        ?int $updatedBy = null,
    ): HostelBed {
        return DB::transaction(function () use ($bed, $status, $notes, $updatedBy) {
            $status = strtolower(trim($status));
            $allowedStatuses = ['available', 'occupied', 'maintenance', 'reserved'];

            if (! in_array($status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'status' => __('Invalid bed status. Allowed values: :statuses', ['statuses' => implode(', ', $allowedStatuses)]),
                ]);
            }

            // Cannot set occupied bed to maintenance or reserved without vacating or transferring
            if ($bed->status === 'occupied' && in_array($status, ['maintenance', 'reserved'], true)) {
                throw ValidationException::withMessages([
                    'status' => __('Cannot mark an occupied bed as :status. Please vacate or transfer the active resident first.', [
                        'status' => $status,
                    ]),
                ]);
            }

            $oldStatus = $bed->status;

            $bed->update([
                'status' => $status,
                'updated_by' => $updatedBy,
            ]);

            if ($bed->room) {
                self::syncRoomStatus($bed->room);
            }

            AuditLog::record(
                action: 'hostel_bed_status_updated',
                details: [
                    'bed_id' => $bed->id,
                    'bed_number' => $bed->bed_number,
                    'room_number' => $bed->room?->room_number,
                    'old_status' => $oldStatus,
                    'new_status' => $status,
                    'notes' => $notes,
                ],
                userId: $updatedBy,
                auditableType: HostelBed::class,
                auditableId: $bed->id,
                description: "Changed status of Bed {$bed->bed_number} (Room {$bed->room?->room_number}) from {$oldStatus} to {$status}"
            );

            return $bed->refresh();
        });
    }

    /**
     * Synchronize room status based on its beds' statuses.
     */
    public static function syncRoomStatus(HostelRoom $room): void
    {
        if (in_array($room->status, ['maintenance', 'inactive'], true)) {
            return;
        }

        $beds = $room->beds()->get();
        if ($beds->isEmpty()) {
            return;
        }

        $totalBeds = $beds->count();
        $occupiedCount = $beds->where('status', 'occupied')->count();
        $maintenanceCount = $beds->where('status', 'maintenance')->count();
        $reservedCount = $beds->where('status', 'reserved')->count();

        if ($occupiedCount === $totalBeds) {
            $room->update(['status' => 'occupied']);
        } elseif ($maintenanceCount === $totalBeds) {
            $room->update(['status' => 'maintenance']);
        } elseif ($reservedCount === $totalBeds) {
            $room->update(['status' => 'reserved']);
        } else {
            $room->update(['status' => 'available']);
        }
    }

    /**
     * Check if extension request submission from user dashboard is enabled in settings.
     */
    public static function isExtensionRequestAllowed(): bool
    {
        return (bool) Setting::get('hostel.allow_resident_extension_requests', true);
    }

    /**
     * Check if soft copy upload is mandatory in settings.
     */
    public static function isSoftCopyMandatory(): bool
    {
        return (bool) Setting::get('hostel.soft_copy_required', false);
    }

    /**
     * Get aggregate statistics across all buildings or a specific building.
     *
     * @return array<string, mixed>
     */
    public static function getStatistics(?int $buildingId = null): array
    {
        $buildingsQuery = HostelBuilding::query();
        $roomsQuery = HostelRoom::query();
        $bedsQuery = HostelBed::query();
        $allocationsQuery = HostelAllocation::active();

        if ($buildingId) {
            $buildingsQuery->where('id', $buildingId);
            $roomsQuery->where('hostel_building_id', $buildingId);
            $bedsQuery->whereHas('room', fn ($q) => $q->where('hostel_building_id', $buildingId));
            $allocationsQuery->whereHas('room', fn ($q) => $q->where('hostel_building_id', $buildingId));
        }

        $totalBuildings = $buildingsQuery->count();
        $totalRooms = $roomsQuery->count();
        $totalBeds = $bedsQuery->count();

        $occupiedBeds = (clone $bedsQuery)->where('status', 'occupied')->count();
        $availableBeds = (clone $bedsQuery)->where('status', 'available')->count();
        $reservedBeds = (clone $bedsQuery)->where('status', 'reserved')->count();
        $maintenanceBeds = (clone $bedsQuery)->where('status', 'maintenance')->count();

        $occupancyRate = $totalBeds > 0 ? round(($occupiedBeds / $totalBeds) * 100, 1) : 0;

        $maleRooms = (clone $roomsQuery)->where('gender_allowed', 'male_only')->count();
        $femaleRooms = (clone $roomsQuery)->where('gender_allowed', 'female_only')->count();
        $coedRooms = (clone $roomsQuery)->where('gender_allowed', 'any')->count();

        $activeAllocations = (clone $allocationsQuery)->count();
        $studentResidents = (clone $allocationsQuery)->where('resident_type', 'student')->count();
        $staffResidents = (clone $allocationsQuery)->where('resident_type', 'staff')->count();

        $expiringNoticeDays = (int) Setting::get('hostel.expiry_notice_days', 15);
        $expiringSoonCount = (clone $allocationsQuery)
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->startOfDay(), now()->addDays($expiringNoticeDays)->endOfDay()])
            ->count();

        $pendingHardCopy = (clone $allocationsQuery)->where('hard_copy_submitted', false)->count();
        $pendingSoftCopy = (clone $allocationsQuery)->whereNull('soft_copy_path')->count();

        $pendingRequests = HostelExtensionRequest::pending()->count();

        return [
            'total_buildings' => $totalBuildings,
            'total_rooms' => $totalRooms,
            'total_beds' => $totalBeds,
            'occupied_beds' => $occupiedBeds,
            'available_beds' => $availableBeds,
            'reserved_beds' => $reservedBeds,
            'maintenance_beds' => $maintenanceBeds,
            'occupancy_rate' => $occupancyRate,
            'male_rooms' => $maleRooms,
            'female_rooms' => $femaleRooms,
            'coed_rooms' => $coedRooms,
            'active_allocations' => $activeAllocations,
            'student_residents' => $studentResidents,
            'staff_residents' => $staffResidents,
            'expiring_soon_count' => $expiringSoonCount,
            'pending_hard_copy' => $pendingHardCopy,
            'pending_soft_copy' => $pendingSoftCopy,
            'pending_extension_requests' => $pendingRequests,
        ];
    }
}
