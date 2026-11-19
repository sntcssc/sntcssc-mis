<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelCaretaker;
use App\Models\HostelExtensionRequest;
use App\Models\HostelFloor;
use App\Models\HostelRoom;
use App\Models\Setting;
use App\Models\User;
use App\Services\FileUploadService;
use App\Services\Hostel\HostelFormService;
use App\Services\Hostel\HostelService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HostelApiController extends Controller
{
    /**
     * Resolve the authenticated user context (via API key binding, Sanctum, or default guard).
     */
    protected function resolveUser(Request $request): ?User
    {
        return $request->user()
            ?? Auth::guard('sanctum')->user()
            ?? ($request->attributes->get('api_key')?->user);
    }

    /**
     * Format a standard API success response.
     */
    protected function success(mixed $data, ?array $meta = null, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Format a standard API error response.
     */
    protected function error(string $code, string $message, int $status = 400, ?array $details = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ($details !== null) {
            $payload['error']['details'] = $details;
        }

        return response()->json($payload, $status);
    }

    // ==========================================
    // 1. DASHBOARD & OVERVIEW
    // ==========================================

    /**
     * Get executive hostel KPIs, building overviews, and live capacity breakdown.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $totalBeds = HostelBed::count();
        $occupiedBeds = HostelBed::where('status', 'occupied')->count();
        $availableBeds = HostelBed::where('status', 'available')->count();
        $maintenanceBeds = HostelBed::where('status', 'maintenance')->count();
        $reservedBeds = HostelBed::where('status', 'reserved')->count();

        $occupancyRate = $totalBeds > 0 ? round(($occupiedBeds / $totalBeds) * 100, 1) : 0.0;

        $pendingExtensions = HostelExtensionRequest::where('status', 'pending')->count();
        $pendingForms = HostelAllocation::active()->where('hard_copy_submitted', false)->count();

        $buildings = HostelBuilding::with(['activeCaretakers'])
            ->withCount([
                'floors',
                'rooms',
                'beds as total_capacity',
                'beds as occupied_beds' => fn ($q) => $q->where('hostel_beds.status', 'occupied'),
                'beds as available_beds' => fn ($q) => $q->where('hostel_beds.status', 'available'),
            ])
            ->orderBy('display_order')
            ->get()
            ->map(fn (HostelBuilding $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'gender_type' => $b->gender_type,
                'total_floors' => $b->floors_count,
                'total_rooms' => $b->rooms_count,
                'total_capacity' => $b->total_capacity,
                'occupied_beds' => $b->occupied_beds,
                'available_beds' => $b->available_beds,
                'occupancy_rate' => $b->total_capacity > 0 ? round(($b->occupied_beds / $b->total_capacity) * 100, 1) : 0.0,
                'warden' => [
                    'name' => $b->warden_name,
                    'phone' => $b->warden_contact,
                    'email' => $b->warden_email,
                ],
                'manager' => [
                    'name' => $b->manager_name,
                    'phone' => $b->manager_contact,
                    'email' => $b->manager_email,
                ],
                'caretakers' => $b->activeCaretakers->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'phone' => $c->phone,
                    'email' => $c->email,
                    'shift' => $c->shift,
                    'is_primary' => (bool) $c->is_primary,
                ]),
            ]);

        // Upcoming stay expirations in the next 30 days
        $expiringSoon = HostelAllocation::with(['user.studentProfile', 'room.building', 'bed'])
            ->active()
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->orderBy('end_date')
            ->take(10)
            ->get()
            ->map(fn (HostelAllocation $a) => [
                'allocation_id' => $a->id,
                'allocation_no' => $a->allocation_no,
                'resident_name' => $a->user?->name,
                'resident_email' => $a->user?->email,
                'building' => $a->room?->building?->name,
                'room_number' => $a->room?->room_number,
                'bed_number' => $a->bed?->bed_number,
                'end_date' => $a->end_date?->toDateString(),
                'days_remaining' => now()->diffInDays($a->end_date, false),
            ]);

        return $this->success([
            'metrics' => [
                'total_capacity' => $totalBeds,
                'occupied_beds' => $occupiedBeds,
                'available_beds' => $availableBeds,
                'maintenance_beds' => $maintenanceBeds,
                'reserved_beds' => $reservedBeds,
                'occupancy_rate' => $occupancyRate,
                'pending_extension_requests' => $pendingExtensions,
                'pending_form_submissions' => $pendingForms,
            ],
            'buildings' => $buildings,
            'expiring_soon' => $expiringSoon,
        ]);
    }

    /**
     * Get real-time graphical visual room board data.
     */
    public function visualBoard(Request $request): JsonResponse
    {
        $buildingId = $request->input('building_id');

        $query = HostelBuilding::with([
            'floors' => fn ($q) => $q->orderBy('floor_number'),
            'floors.rooms' => fn ($q) => $q->orderBy('room_number'),
            'floors.rooms.beds' => fn ($q) => $q->orderBy('bed_number'),
            'floors.rooms.beds.currentAllocation.user',
        ]);

        if ($buildingId) {
            $query->where('id', $buildingId);
        }

        $board = $query->orderBy('display_order')->get()->map(function (HostelBuilding $b) {
            return [
                'building_id' => $b->id,
                'building_name' => $b->name,
                'building_code' => $b->code,
                'gender_type' => $b->gender_type,
                'floors' => $b->floors->map(function (HostelFloor $f) {
                    return [
                        'floor_id' => $f->id,
                        'floor_number' => $f->floor_number,
                        'floor_name' => $f->name,
                        'rooms' => $f->rooms->map(function (HostelRoom $r) {
                            return [
                                'room_id' => $r->id,
                                'room_number' => $r->room_number,
                                'room_type' => $r->room_type,
                                'capacity' => $r->capacity,
                                'gender_allowed' => $r->gender_allowed,
                                'status' => $r->status,
                                'beds' => $r->beds->map(function (HostelBed $bed) {
                                    $alloc = $bed->currentAllocation;

                                    return [
                                        'bed_id' => $bed->id,
                                        'bed_number' => $bed->bed_number,
                                        'status' => $bed->status,
                                        'is_occupied' => $bed->status === 'occupied',
                                        'resident' => $alloc ? [
                                            'allocation_id' => $alloc->id,
                                            'allocation_no' => $alloc->allocation_no,
                                            'user_id' => $alloc->user_id,
                                            'name' => $alloc->user?->name,
                                            'urn' => $alloc->user?->urn,
                                            'start_date' => $alloc->start_date?->toDateString(),
                                            'end_date' => $alloc->end_date?->toDateString(),
                                        ] : null,
                                    ];
                                }),
                            ];
                        }),
                    ];
                }),
            ];
        });

        return $this->success($board);
    }

    // ==========================================
    // 2. BUILDINGS & FLOORS
    // ==========================================

    /**
     * List all hostel buildings.
     */
    public function buildings(Request $request): JsonResponse
    {
        $query = HostelBuilding::with(['activeCaretakers'])
            ->withCount(['floors', 'rooms', 'beds as total_beds']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('warden_name', 'like', "%{$search}%")
                    ->orWhere('manager_name', 'like', "%{$search}%");
            });
        }

        if ($gender = $request->input('gender_type')) {
            $query->where('gender_type', $gender);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $buildings = $query->orderBy('display_order')->get();

        return $this->success($buildings);
    }

    /**
     * Show single building details including floors and caretakers.
     */
    public function showBuilding(int|string $id): JsonResponse
    {
        $building = HostelBuilding::with(['floors.rooms.beds', 'caretakers'])
            ->withCount(['floors', 'rooms', 'beds'])
            ->find($id);

        if (! $building) {
            return $this->error('BUILDING_NOT_FOUND', "Hostel building with ID '{$id}' was not found.", 404);
        }

        return $this->success($building);
    }

    /**
     * Create a new building and automatically generate floor records.
     */
    public function storeBuilding(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:120|unique:hostel_buildings,name',
            'code' => 'required|string|max:30|uppercase|unique:hostel_buildings,code',
            'gender_type' => 'required|in:boys,girls,coed,faculty',
            'total_floors' => 'required|integer|min:1|max:20',
            'warden_name' => 'nullable|string|max:120',
            'warden_contact' => 'nullable|string|max:40',
            'warden_email' => 'nullable|email|max:120',
            'manager_name' => 'nullable|string|max:120',
            'manager_contact' => 'nullable|string|max:40',
            'manager_email' => 'nullable|email|max:120',
            'description' => 'nullable|string|max:500',
            'status' => 'nullable|in:active,maintenance,inactive',
            'display_order' => 'nullable|integer',
            'caretakers' => 'nullable|array',
            'caretakers.*.name' => 'required|string|max:120',
            'caretakers.*.phone' => 'nullable|string|max:40',
            'caretakers.*.email' => 'nullable|email|max:120',
            'caretakers.*.shift' => 'nullable|in:day,night,rotational',
            'caretakers.*.is_primary' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid building payload.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        $building = DB::transaction(function () use ($request, $user) {
            $building = HostelBuilding::create([
                'name' => trim((string) $request->input('name')),
                'code' => strtoupper(trim((string) $request->input('code'))),
                'gender_type' => $request->input('gender_type'),
                'total_floors' => (int) $request->input('total_floors'),
                'warden_name' => $request->input('warden_name'),
                'warden_contact' => $request->input('warden_contact'),
                'warden_email' => $request->input('warden_email'),
                'manager_name' => $request->input('manager_name'),
                'manager_contact' => $request->input('manager_contact'),
                'manager_email' => $request->input('manager_email'),
                'description' => $request->input('description'),
                'status' => $request->input('status', 'active'),
                'display_order' => (int) $request->input('display_order', 0),
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

            // Auto-generate floors
            for ($i = 0; $i < $building->total_floors; $i++) {
                $floorName = $i === 0 ? 'Ground Floor' : ($i === 1 ? '1st Floor' : ($i === 2 ? '2nd Floor' : ($i === 3 ? '3rd Floor' : "{$i}th Floor")));
                HostelFloor::create([
                    'hostel_building_id' => $building->id,
                    'floor_number' => $i,
                    'name' => $floorName,
                    'created_by' => $user?->id,
                    'updated_by' => $user?->id,
                ]);
            }

            // Provision caretakers if provided
            if ($caretakers = $request->input('caretakers')) {
                foreach ($caretakers as $c) {
                    HostelCaretaker::create([
                        'hostel_building_id' => $building->id,
                        'name' => $c['name'],
                        'phone' => $c['phone'] ?? null,
                        'email' => $c['email'] ?? null,
                        'shift' => $c['shift'] ?? 'rotational',
                        'is_primary' => (bool) ($c['is_primary'] ?? false),
                        'status' => 'active',
                        'created_by' => $user?->id,
                        'updated_by' => $user?->id,
                    ]);
                }
            }

            return $building->load(['floors', 'caretakers']);
        });

        return $this->success($building, null, 201);
    }

    /**
     * Update an existing hostel building.
     */
    public function updateBuilding(Request $request, int|string $id): JsonResponse
    {
        $building = HostelBuilding::find($id);
        if (! $building) {
            return $this->error('BUILDING_NOT_FOUND', "Hostel building with ID '{$id}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:120|unique:hostel_buildings,name,'.$building->id,
            'code' => 'sometimes|required|string|max:30|uppercase|unique:hostel_buildings,code,'.$building->id,
            'gender_type' => 'sometimes|required|in:boys,girls,coed,faculty',
            'warden_name' => 'nullable|string|max:120',
            'warden_contact' => 'nullable|string|max:40',
            'warden_email' => 'nullable|email|max:120',
            'manager_name' => 'nullable|string|max:120',
            'manager_contact' => 'nullable|string|max:40',
            'manager_email' => 'nullable|email|max:120',
            'description' => 'nullable|string|max:500',
            'status' => 'sometimes|required|in:active,maintenance,inactive',
            'display_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid building payload.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);
        $data = $validator->validated();
        $data['updated_by'] = $user?->id;

        $building->update($data);

        return $this->success($building->fresh(['floors', 'caretakers']));
    }

    /**
     * Delete / archive a building.
     */
    public function destroyBuilding(int|string $id): JsonResponse
    {
        $building = HostelBuilding::withCount(['allocations' => fn ($q) => $q->whereIn('hostel_allocations.status', ['active', 'extended', 'allocated'])])->find($id);

        if (! $building) {
            return $this->error('BUILDING_NOT_FOUND', "Hostel building with ID '{$id}' was not found.", 404);
        }

        if ($building->allocations_count > 0) {
            return $this->error('ACTIVE_ALLOCATIONS_EXIST', 'Cannot delete building because it currently houses active resident allocations.', 409);
        }

        $building->delete();

        return $this->success(['message' => 'Building successfully archived.']);
    }

    // ==========================================
    // 3. ROOMS & BEDS
    // ==========================================

    /**
     * List rooms with pagination, search, and multidimensional filters.
     */
    public function rooms(Request $request): JsonResponse
    {
        $query = HostelRoom::with(['building', 'floor', 'beds.currentAllocation.user']);

        if ($buildingId = $request->input('building_id')) {
            $query->where('hostel_building_id', $buildingId);
        }

        if ($floorId = $request->input('floor_id')) {
            $query->where('hostel_floor_id', $floorId);
        }

        if ($type = $request->input('room_type')) {
            $query->where('room_type', $type);
        }

        if ($gender = $request->input('gender_allowed')) {
            $query->where('gender_allowed', $gender);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('room_number', 'like', "%{$search}%")
                    ->orWhereHas('building', fn ($b) => $b->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            });
        }

        $perPage = min(100, max(5, $request->integer('per_page', 25)));
        $rooms = $query->orderBy('room_number')->paginate($perPage);

        return $this->success($rooms->items(), [
            'current_page' => $rooms->currentPage(),
            'last_page' => $rooms->lastPage(),
            'per_page' => $rooms->perPage(),
            'total' => $rooms->total(),
        ]);
    }

    /**
     * Show single room details including all beds and active residents.
     */
    public function showRoom(int|string $id): JsonResponse
    {
        $room = HostelRoom::with(['building', 'floor', 'beds.currentAllocation.user'])->find($id);

        if (! $room) {
            return $this->error('ROOM_NOT_FOUND', "Hostel room with ID '{$id}' was not found.", 404);
        }

        return $this->success($room);
    }

    /**
     * Create a new room and automatically provision capacity-matched beds.
     */
    public function storeRoom(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'hostel_building_id' => 'required|exists:hostel_buildings,id',
            'hostel_floor_id' => 'required|exists:hostel_floors,id',
            'room_number' => 'required|string|max:30',
            'room_type' => 'required|in:single,double,triple,dormitory',
            'capacity' => 'required|integer|min:1|max:12',
            'gender_allowed' => 'required|in:any,male_only,female_only',
            'amenities' => 'nullable|array',
            'status' => 'nullable|in:available,occupied,maintenance,reserved',
            'monthly_fee' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid room payload.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        // Ensure room number is unique within building
        $exists = HostelRoom::where('hostel_building_id', $request->input('hostel_building_id'))
            ->where('room_number', trim((string) $request->input('room_number')))
            ->exists();

        if ($exists) {
            return $this->error('DUPLICATE_ROOM', "Room number '{$request->input('room_number')}' already exists in this building.", 409);
        }

        $room = DB::transaction(function () use ($request, $user) {
            $capacity = (int) $request->input('capacity');
            $room = HostelRoom::create([
                'hostel_building_id' => $request->input('hostel_building_id'),
                'hostel_floor_id' => $request->input('hostel_floor_id'),
                'room_number' => trim((string) $request->input('room_number')),
                'room_type' => $request->input('room_type'),
                'capacity' => $capacity,
                'gender_allowed' => $request->input('gender_allowed'),
                'amenities' => $request->input('amenities', []),
                'status' => $request->input('status', 'available'),
                'monthly_fee' => $request->input('monthly_fee'),
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

            // Auto-create beds
            for ($i = 1; $i <= $capacity; $i++) {
                $bedCode = $capacity === 1 ? 'A' : chr(64 + $i);
                HostelBed::create([
                    'hostel_room_id' => $room->id,
                    'bed_number' => "{$room->room_number}-{$bedCode}",
                    'status' => 'available',
                    'created_by' => $user?->id,
                    'updated_by' => $user?->id,
                ]);
            }

            return $room->load(['building', 'floor', 'beds']);
        });

        return $this->success($room, null, 201);
    }

    /**
     * Update an existing room.
     */
    public function updateRoom(Request $request, int|string $id): JsonResponse
    {
        $room = HostelRoom::find($id);
        if (! $room) {
            return $this->error('ROOM_NOT_FOUND', "Hostel room with ID '{$id}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'room_number' => 'sometimes|required|string|max:30',
            'gender_allowed' => 'sometimes|required|in:any,male_only,female_only',
            'amenities' => 'nullable|array',
            'status' => 'sometimes|required|in:available,occupied,maintenance,reserved',
            'monthly_fee' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid room update payload.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);
        $data = $validator->validated();
        $data['updated_by'] = $user?->id;

        $room->update($data);

        return $this->success($room->fresh(['building', 'floor', 'beds']));
    }

    /**
     * Delete room if unoccupied.
     */
    public function destroyRoom(int|string $id): JsonResponse
    {
        $room = HostelRoom::withCount(['beds as occupied_beds' => fn ($q) => $q->where('status', 'occupied')])->find($id);

        if (! $room) {
            return $this->error('ROOM_NOT_FOUND', "Hostel room with ID '{$id}' was not found.", 404);
        }

        if ($room->occupied_beds > 0) {
            return $this->error('ROOM_OCCUPIED', 'Cannot delete room because it currently contains occupied beds.', 409);
        }

        DB::transaction(function () use ($room) {
            $room->beds()->delete();
            $room->delete();
        });

        return $this->success(['message' => 'Room successfully deleted.']);
    }

    /**
     * Update bed operational status (e.g. available, maintenance, reserved).
     */
    public function updateBedStatus(Request $request, int|string $id): JsonResponse
    {
        $bed = HostelBed::with('room.building')->find($id);
        if (! $bed) {
            return $this->error('BED_NOT_FOUND', "Hostel bed with ID '{$id}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:available,occupied,maintenance,reserved',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid bed status payload.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        try {
            $updated = HostelService::updateBedStatus(
                $bed,
                $request->input('status'),
                $request->input('notes'),
                $user?->id
            );

            return $this->success($updated->fresh(['room.building']));
        } catch (ValidationException $e) {
            return $this->error('STATUS_UPDATE_REJECTED', $e->getMessage(), 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->error('STATUS_UPDATE_FAILED', $e->getMessage(), 500);
        }
    }

    // ==========================================
    // 4. ALLOCATIONS & RESIDENTS
    // ==========================================

    /**
     * List all residential allocations with filters & pagination.
     */
    public function allocations(Request $request): JsonResponse
    {
        $query = HostelAllocation::with([
            'user.studentProfile',
            'user.activeEnrollment.course',
            'user.activeEnrollment.batch',
            'room.building',
            'room.floor',
            'bed',
        ]);

        if ($buildingId = $request->input('building_id')) {
            $query->whereHas('room', fn ($q) => $q->where('hostel_building_id', $buildingId));
        }

        if ($roomId = $request->input('room_id')) {
            $query->where('hostel_room_id', $roomId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($residentType = $request->input('resident_type')) {
            $query->where('resident_type', $residentType);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('allocation_no', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('urn', 'like', "%{$search}%")
                            ->orWhere('student_id', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = min(100, max(5, $request->integer('per_page', 25)));
        $allocations = $query->latest('id')->paginate($perPage);

        return $this->success($allocations->items(), [
            'current_page' => $allocations->currentPage(),
            'last_page' => $allocations->lastPage(),
            'per_page' => $allocations->perPage(),
            'total' => $allocations->total(),
        ]);
    }

    /**
     * Show single allocation dossier.
     */
    public function showAllocation(int|string $id): JsonResponse
    {
        $allocation = HostelAllocation::with([
            'user.studentProfile',
            'user.activeEnrollment.course',
            'user.activeEnrollment.batch',
            'user.activeEnrollment.section',
            'room.building.activeCaretakers',
            'room.floor',
            'bed',
            'extensionRequests.reviewer',
        ])->find($id);

        if (! $allocation) {
            return $this->error('ALLOCATION_NOT_FOUND', "Hostel allocation with ID '{$id}' was not found.", 404);
        }

        return $this->success($allocation);
    }

    /**
     * Allot a bed to a student or staff resident.
     */
    public function storeAllocation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'hostel_bed_id' => 'required|exists:hostel_beds,id',
            'resident_type' => 'nullable|in:student,staff,faculty,guest',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'duration_months' => 'nullable|integer|min:1|max:36',
            'monthly_rent' => 'nullable|numeric|min:0',
            'security_deposit' => 'nullable|numeric|min:0',
            'remarks' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid allocation payload.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        try {
            $data = $validator->validated();
            $allocation = HostelService::allocateResident($data, $user?->id);

            return $this->success($allocation->load(['room.building', 'bed', 'user']), null, 201);
        } catch (ValidationException $e) {
            return $this->error('ALLOCATION_REJECTED', $e->getMessage(), 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->error('ALLOCATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Administratively extend an active allocation.
     */
    public function extendAllocation(Request $request, int|string $id): JsonResponse
    {
        $allocation = HostelAllocation::find($id);
        if (! $allocation) {
            return $this->error('ALLOCATION_NOT_FOUND', "Hostel allocation with ID '{$id}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'new_end_date' => 'required|date|after:today',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid extension payload.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        $extended = HostelService::extendAllocation(
            $allocation,
            $request->input('new_end_date'),
            $request->input('notes'),
            $user?->id
        );

        return $this->success($extended);
    }

    /**
     * Check out / vacate a resident.
     */
    public function vacateAllocation(Request $request, int|string $id): JsonResponse
    {
        $allocation = HostelAllocation::find($id);
        if (! $allocation) {
            return $this->error('ALLOCATION_NOT_FOUND', "Hostel allocation with ID '{$id}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'vacated_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid checkout payload.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        $vacated = HostelService::vacateAllocation(
            $allocation,
            $request->input('vacated_date'),
            $request->input('remarks'),
            $user?->id
        );

        return $this->success($vacated);
    }

    /**
     * Transfer an existing resident to a new room & bed.
     */
    public function transferAllocation(Request $request, int|string $id): JsonResponse
    {
        $allocation = HostelAllocation::with(['room.building', 'bed', 'user'])->find($id);
        if (! $allocation) {
            return $this->error('ALLOCATION_NOT_FOUND', "Hostel allocation with ID '{$id}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'new_hostel_bed_id' => 'required|exists:hostel_beds,id',
            'transfer_date' => 'nullable|date',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid transfer payload.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        try {
            $transferred = HostelService::transferResident(
                $allocation,
                (int) $request->input('new_hostel_bed_id'),
                $request->input('transfer_date'),
                $request->input('reason'),
                $user?->id
            );

            return $this->success($transferred->fresh(['room.building', 'bed', 'user']));
        } catch (ValidationException $e) {
            return $this->error('TRANSFER_REJECTED', $e->getMessage(), 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->error('TRANSFER_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Mark physical or soft-copy admission form as verified.
     */
    public function verifyAllocationForm(Request $request, int|string $id): JsonResponse
    {
        $allocation = HostelAllocation::find($id);
        if (! $allocation) {
            return $this->error('ALLOCATION_NOT_FOUND', "Hostel allocation with ID '{$id}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'form_submitted' => 'nullable|boolean',
            'soft_copy_verified' => 'nullable|boolean',
            'remarks' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid verification payload.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);
        $data = [];

        if ($request->has('form_submitted') || $request->has('hard_copy_submitted')) {
            $submitted = (bool) ($request->input('form_submitted') ?? $request->input('hard_copy_submitted'));
            $data['hard_copy_submitted'] = $submitted;
            if ($submitted && ! $allocation->hard_copy_submitted_at) {
                $data['hard_copy_submitted_at'] = now()->toDateString();
                $data['hard_copy_received_by'] = $user?->id;
            }
        }

        if ($request->has('soft_copy_verified')) {
            $verified = (bool) $request->input('soft_copy_verified');
            $data['soft_copy_verified'] = $verified;
            if ($verified) {
                $data['soft_copy_verified_at'] = now();
                $data['soft_copy_verified_by'] = $user?->id;
            }
        }

        if ($request->filled('remarks')) {
            $data['remarks'] = $request->input('remarks');
        }

        $data['updated_by'] = $user?->id;

        $allocation->update($data);

        $fresh = $allocation->fresh();
        // Append form_submitted alias to response
        $payload = $fresh->toArray();
        $payload['form_submitted'] = (bool) $fresh->hard_copy_submitted;

        return $this->success($payload);
    }

    // ==========================================
    // 5. RESIDENT PERSONAL PORTAL (MOBILE & WEB APPS)
    // ==========================================

    /**
     * Get authenticated resident's active accommodation dossier, room, bed, roommates, and past stays.
     */
    public function myAccommodation(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        // Support admin inspecting a resident if passed via query and caller has hostel view scope
        if (! $user || ($request->has('user_id') && ($user->can('hostel.view') || $request->attributes->get('api_key')?->hasScope('read:hostel')))) {
            $targetUserId = $request->input('user_id', $user?->id);
            $user = User::find($targetUserId);
        }

        if (! $user) {
            return $this->error('AUTHENTICATION_REQUIRED', 'This endpoint requires an authenticated resident user context or an API key with an associated resident.', 401);
        }

        $currentAllocation = HostelAllocation::with([
            'room.building.activeCaretakers',
            'room.floor',
            'room.beds.currentAllocation.user',
            'bed',
            'user.studentProfile',
            'user.activeEnrollment.course',
            'user.activeEnrollment.batch',
            'user.activeEnrollment.section',
        ])
            ->where('user_id', $user->id)
            ->active()
            ->latest('id')
            ->first();

        $roommates = collect();
        if ($currentAllocation && $currentAllocation->room?->isSharing()) {
            $roommates = $currentAllocation->room->beds
                ->filter(fn ($b) => $b->id !== $currentAllocation->hostel_bed_id && $b->currentAllocation)
                ->map(fn ($b) => [
                    'bed_number' => $b->bed_number,
                    'resident_name' => $b->currentAllocation?->user?->name,
                    'resident_urn' => $b->currentAllocation?->user?->urn,
                    'student_id' => $b->currentAllocation?->user?->student_id,
                ])
                ->values();
        }

        $previousAllocations = HostelAllocation::with(['room.building', 'bed'])
            ->where('user_id', $user->id)
            ->whereIn('status', ['checked_out', 'vacated', 'cancelled'])
            ->latest('id')
            ->get();

        return $this->success([
            'resident' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'urn' => $user->urn,
                'student_id' => $user->student_id,
            ],
            'current_allocation' => $currentAllocation,
            'roommates' => $roommates,
            'previous_allocations' => $previousAllocations,
            'can_request_extension' => HostelService::isExtensionRequestAllowed(),
            'is_soft_copy_mandatory' => HostelService::isSoftCopyMandatory(),
        ]);
    }

    /**
     * Submit accommodation stay extension request from resident app.
     */
    public function submitExtensionRequest(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('AUTHENTICATION_REQUIRED', 'Authenticated resident context is required.', 401);
        }

        $allocation = HostelAllocation::where('user_id', $user->id)->active()->latest('id')->first();
        if (! $allocation) {
            return $this->error('NO_ACTIVE_ALLOCATION', 'No active accommodation allotment found to extend.', 404);
        }

        $validator = Validator::make($request->all(), [
            'extension_type' => 'required|in:months,days,custom',
            'extension_value' => 'nullable|required_if:extension_type,months,days|integer|min:1',
            'custom_date' => 'nullable|required_if:extension_type,custom|date|after:today',
            'reason' => 'required|string|min:10|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid extension request parameters.', 422, $validator->errors()->toArray());
        }

        $baseDate = $allocation->end_date ? $allocation->end_date->copy() : now();
        $requestedDate = match ($request->input('extension_type')) {
            'months' => $baseDate->copy()->addMonths((int) $request->input('extension_value')),
            'days' => $baseDate->copy()->addDays((int) $request->input('extension_value')),
            'custom' => Carbon::parse($request->input('custom_date')),
        };

        try {
            $extensionRequest = HostelService::submitExtensionRequest(
                $allocation,
                $requestedDate->toDateString(),
                $request->input('extension_type'),
                $request->input('extension_value'),
                $request->input('reason'),
                $user->id
            );

            return $this->success($extensionRequest, null, 201);
        } catch (ValidationException $e) {
            return $this->error('REQUEST_DISALLOWED', $e->getMessage(), 422, $e->errors());
        }
    }

    /**
     * Upload signed soft-copy admission form.
     */
    public function uploadSoftCopy(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('AUTHENTICATION_REQUIRED', 'Authenticated resident context is required.', 401);
        }

        $allocation = HostelAllocation::where('user_id', $user->id)->active()->latest('id')->first();
        if (! $allocation) {
            return $this->error('NO_ACTIVE_ALLOCATION', 'No active accommodation allotment found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid file upload. Only PDF, JPG, PNG under 10MB are allowed.', 422, $validator->errors()->toArray());
        }

        $path = FileUploadService::store(
            file: $request->file('file'),
            folder: 'hostel/forms',
            prefix: 'hostel_form_'.$user->id,
            oldPath: $allocation->soft_copy_path ?: null
        );

        $allocation->update([
            'soft_copy_path' => $path,
            'form_submitted' => true,
            'updated_by' => $user->id,
        ]);

        return $this->success([
            'message' => 'Hostel admission form soft-copy uploaded successfully.',
            'soft_copy_url' => asset('storage/'.$path),
        ]);
    }

    /**
     * List current resident's extension requests.
     */
    public function myExtensionRequests(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('AUTHENTICATION_REQUIRED', 'Authenticated resident context is required.', 401);
        }

        $requests = HostelExtensionRequest::with(['reviewer'])
            ->where('user_id', $user->id)
            ->latest('id')
            ->get();

        return $this->success($requests);
    }

    // ==========================================
    // 6. EXTENSION REQUESTS (ADMIN & STAFF REVIEW)
    // ==========================================

    /**
     * List all accommodation extension requests.
     */
    public function extensionRequests(Request $request): JsonResponse
    {
        $query = HostelExtensionRequest::with([
            'allocation.room.building',
            'allocation.bed',
            'user.studentProfile',
            'reviewer',
        ]);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $perPage = min(100, max(5, $request->integer('per_page', 25)));
        $requests = $query->latest('id')->paginate($perPage);

        return $this->success($requests->items(), [
            'current_page' => $requests->currentPage(),
            'last_page' => $requests->lastPage(),
            'per_page' => $requests->perPage(),
            'total' => $requests->total(),
        ]);
    }

    /**
     * Approve or reject resident accommodation extension request.
     */
    public function reviewExtensionRequest(Request $request, int|string $id): JsonResponse
    {
        $extensionRequest = HostelExtensionRequest::find($id);
        if (! $extensionRequest) {
            return $this->error('REQUEST_NOT_FOUND', "Extension request with ID '{$id}' was not found.", 404);
        }

        if ($extensionRequest->status !== 'pending') {
            return $this->error('ALREADY_REVIEWED', "This extension request has already been {$extensionRequest->status}.", 409);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject',
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid review payload.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);
        $action = $request->input('action');
        $adminNotes = $request->input('admin_notes');

        if ($action === 'approve') {
            $reviewed = HostelService::approveExtensionRequest($extensionRequest, $user?->id, $adminNotes);
        } else {
            if (empty($adminNotes)) {
                return $this->error('REASON_REQUIRED', 'Admin notes / reason is mandatory when rejecting an extension request.', 422);
            }
            $reviewed = HostelService::rejectExtensionRequest($extensionRequest, $user?->id, $adminNotes);
        }

        return $this->success($reviewed);
    }

    // ==========================================
    // 7. FORM DOWNLOAD & POLICIES
    // ==========================================

    /**
     * Download the official personalized hostel admission form PDF.
     */
    public function downloadForm(Request $request): Response|BinaryFileResponse|JsonResponse
    {
        $user = $this->resolveUser($request);
        $allocationId = $request->query('allocation_id');
        $allocation = null;

        if ($allocationId) {
            $allocation = HostelAllocation::find($allocationId);
            if ($allocation && $user && $allocation->user_id !== $user->id) {
                // If not self, verify admin permissions or read:hostel scope
                $hasScope = $request->attributes->get('api_key')?->hasScope('download:hostel_forms')
                    || $request->attributes->get('api_key')?->hasScope('read:hostel')
                    || $request->attributes->get('api_key')?->hasScope('*');

                if (! $hasScope && ! $user->can('hostel.allocations.view') && ! $user->can('hostel.allocations.manage')) {
                    return $this->error('UNAUTHORIZED', "Unauthorized to access another resident's hostel admission form.", 403);
                }
            }
        }

        if (! $allocation && $user) {
            $allocation = $user->activeHostelAllocation;
        }

        $resident = ($allocation && $allocation->user) ? $allocation->user : $user;

        if (! $resident) {
            return $this->error('RESIDENT_REQUIRED', 'Could not resolve resident for form generation. Pass allocation_id or authenticate as resident.', 400);
        }

        return HostelFormService::downloadForm($resident, $allocation);
    }

    /**
     * Get system hostel policies, rules notice, and operational configuration.
     */
    public function policies(Request $request): JsonResponse
    {
        return $this->success([
            'rules_notice' => (string) Setting::get('hostel.rules_notice', ''),
            'default_duration_months' => (int) Setting::get('hostel.default_duration_months', 6),
            'expiry_notice_days' => (int) Setting::get('hostel.expiry_notice_days', 15),
            'allow_resident_extension_requests' => (bool) Setting::get('hostel.allow_resident_extension_requests', true),
            'soft_copy_mandatory' => (bool) Setting::get('hostel.soft_copy_required', false),
            'form_download_mode' => (string) Setting::get('hostel.form_download_mode', 'generated'),
            'helpdesk_contact' => [
                'email' => 'hostel@sntcssc.in',
                'hours' => 'Monday to Saturday · 08:00 AM to 08:00 PM IST',
                'emergency_line' => '+91 (033) 2345-6789',
            ],
        ]);
    }

    // ==========================================
    // 8. REPORTS & ANALYTICS
    // ==========================================

    /**
     * Get campus capacity & occupancy report data.
     */
    public function occupancyReport(Request $request): JsonResponse
    {
        $buildings = HostelBuilding::with(['floors.rooms.beds'])
            ->withCount(['floors', 'rooms', 'beds'])
            ->orderBy('display_order')
            ->get()
            ->map(function (HostelBuilding $b) {
                $beds = $b->floors->flatMap->rooms->flatMap->beds;
                $totalBeds = $beds->count();
                $occupied = $beds->where('status', 'occupied')->count();
                $available = $beds->where('status', 'available')->count();
                $maintenance = $beds->where('status', 'maintenance')->count();

                return [
                    'building_id' => $b->id,
                    'name' => $b->name,
                    'code' => $b->code,
                    'gender_type' => $b->gender_type,
                    'total_floors' => $b->floors_count,
                    'total_rooms' => $b->rooms_count,
                    'total_beds' => $totalBeds,
                    'occupied_beds' => $occupied,
                    'available_beds' => $available,
                    'maintenance_beds' => $maintenance,
                    'occupancy_percentage' => $totalBeds > 0 ? round(($occupied / $totalBeds) * 100, 1) : 0,
                ];
            });

        return $this->success($buildings);
    }

    /**
     * Get admission form compliance audit report data.
     */
    public function complianceReport(Request $request): JsonResponse
    {
        $activeAllocations = HostelAllocation::active()->count();
        $physicalSubmitted = HostelAllocation::active()->where('form_submitted', true)->count();
        $softCopyUploaded = HostelAllocation::active()->whereNotNull('soft_copy_path')->count();
        $softCopyVerified = HostelAllocation::active()->where('soft_copy_verified', true)->count();

        return $this->success([
            'total_active_residents' => $activeAllocations,
            'physical_form_submitted' => $physicalSubmitted,
            'physical_form_pending' => max(0, $activeAllocations - $physicalSubmitted),
            'physical_compliance_rate' => $activeAllocations > 0 ? round(($physicalSubmitted / $activeAllocations) * 100, 1) : 0,
            'soft_copy_uploaded' => $softCopyUploaded,
            'soft_copy_verified' => $softCopyVerified,
            'soft_copy_pending_review' => max(0, $softCopyUploaded - $softCopyVerified),
        ]);
    }

    /**
     * Get upcoming stay expirations forecast.
     */
    public function expiringReport(Request $request): JsonResponse
    {
        $days = max(1, min(90, $request->integer('days', 30)));

        $expiring = HostelAllocation::with(['user.studentProfile', 'room.building', 'bed'])
            ->active()
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->orderBy('end_date')
            ->get()
            ->map(fn (HostelAllocation $a) => [
                'allocation_id' => $a->id,
                'allocation_no' => $a->allocation_no,
                'resident' => [
                    'id' => $a->user_id,
                    'name' => $a->user?->name,
                    'email' => $a->user?->email,
                    'phone' => $a->user?->phone,
                    'urn' => $a->user?->urn,
                    'student_id' => $a->user?->student_id,
                ],
                'building' => $a->room?->building?->name,
                'room_number' => $a->room?->room_number,
                'bed_number' => $a->bed?->bed_number,
                'start_date' => $a->start_date?->toDateString(),
                'end_date' => $a->end_date?->toDateString(),
                'days_remaining' => now()->diffInDays($a->end_date, false),
            ]);

        return $this->success($expiring, [
            'threshold_days' => $days,
            'total_expiring' => $expiring->count(),
        ]);
    }

    // ==========================================
    // 6. RESIDENT VERIFICATION & ACCESS CONTROL
    // ==========================================

    /**
     * Fast Gatekeeper / Reception Kiosk resident verification for security guards.
     */
    public function verifyResident(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Verification identifier is required.', 422, $validator->errors()->toArray());
        }

        $identifier = trim((string) $request->input('identifier'));

        // 1. Try finding by Allocation Number
        $allocation = HostelAllocation::with([
            'user.studentProfile',
            'user.activeEnrollment.course',
            'user.activeEnrollment.batch',
            'room.building.caretakers' => fn ($q) => $q->active(),
            'room.floor',
            'bed',
        ])->where('allocation_no', $identifier)->first();

        // 2. If not found by allocation_no, look up by User attributes
        if (! $allocation) {
            $user = User::query()
                ->where('urn', $identifier)
                ->orWhere('student_id', $identifier)
                ->orWhere('email', $identifier)
                ->orWhere('phone', $identifier)
                ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0)
                ->orWhereHas('studentProfile', function ($q) use ($identifier) {
                    $q->where('urn', $identifier)
                        ->orWhere('roll_number', $identifier)
                        ->orWhere('application_no', $identifier);
                })
                ->first();

            if ($user) {
                $allocation = HostelAllocation::with([
                    'user.studentProfile',
                    'user.activeEnrollment.course',
                    'user.activeEnrollment.batch',
                    'room.building.caretakers' => fn ($q) => $q->active(),
                    'room.floor',
                    'bed',
                ])->where('user_id', $user->id)->active()->latest()->first();
            }
        }

        if (! $allocation) {
            return $this->error('NOT_A_RESIDENT', "No active residential allocation found for '{$identifier}'.", 404, [
                'is_valid' => false,
                'identifier' => $identifier,
            ]);
        }

        $isActive = in_array($allocation->status, ['active', 'extended', 'allocated'], true);
        $isNotExpired = ! $allocation->end_date || Carbon::parse($allocation->end_date)->endOfDay()->isFuture();
        $isValid = $isActive && $isNotExpired;
        $daysRemaining = $allocation->end_date ? (int) now()->diffInDays(Carbon::parse($allocation->end_date), false) : null;

        $user = $allocation->user;
        $profile = $user?->studentProfile;

        // Record gate verification audit
        AuditLog::record(
            action: 'hostel_resident_verified',
            details: [
                'identifier' => $identifier,
                'is_valid' => $isValid,
                'allocation_no' => $allocation->allocation_no,
                'user_id' => $allocation->user_id,
                'resident_name' => $user?->name,
                'building' => $allocation->room?->building?->name,
                'room' => $allocation->room?->room_number,
                'bed' => $allocation->bed?->bed_number,
            ],
            userId: $this->resolveUser($request)?->id,
            auditableType: HostelAllocation::class,
            auditableId: $allocation->id,
            description: "Resident verification check for {$user?->name}: ".($isValid ? 'VALID' : 'INVALID/EXPIRED')
        );

        $payload = [
            'is_valid' => $isValid,
            'verification_status' => $isValid ? 'VERIFIED_ACTIVE' : ($isActive ? 'EXPIRED' : strtoupper($allocation->status)),
            'verified_at' => now()->toIso8601String(),
            'resident' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
                'phone' => $user?->phone,
                'gender' => $user?->gender ?? $profile?->gender ?? 'N/A',
                'urn' => $user?->urn ?? $profile?->urn,
                'student_id' => $user?->student_id,
                'roll_number' => $profile?->roll_number,
                'course' => $user?->activeEnrollment?->course?->name,
                'batch' => $user?->activeEnrollment?->batch?->name,
                'avatar_url' => $user?->profile_photo_url ?? null,
                'emergency_contact' => $profile?->emergency_contact_number ?? $profile?->guardian_phone,
                'guardian_name' => $profile?->guardian_name,
            ],
            'accommodation' => [
                'allocation_id' => $allocation->id,
                'allocation_no' => $allocation->allocation_no,
                'status' => $allocation->status,
                'building' => [
                    'id' => $allocation->room?->building?->id,
                    'name' => $allocation->room?->building?->name,
                    'code' => $allocation->room?->building?->code,
                    'gender_type' => $allocation->room?->building?->gender_type,
                    'warden_name' => $allocation->room?->building?->warden_name,
                    'warden_contact' => $allocation->room?->building?->warden_contact,
                ],
                'floor' => $allocation->room?->floor?->name ?? "Floor {$allocation->room?->floor?->floor_number}",
                'room_number' => $allocation->room?->room_number,
                'room_type' => $allocation->room?->room_type,
                'bed_number' => $allocation->bed?->bed_number,
                'start_date' => $allocation->start_date?->toDateString(),
                'end_date' => $allocation->end_date?->toDateString(),
                'days_remaining' => $daysRemaining,
                'hard_copy_submitted' => (bool) $allocation->form_submitted,
                'soft_copy_verified' => (bool) $allocation->soft_copy_verified,
            ],
            'building_caretakers' => $allocation->room?->building?->caretakers?->map(fn (HostelCaretaker $c) => [
                'name' => $c->name,
                'phone' => $c->phone,
                'shift' => $c->shift_label,
                'is_primary' => (bool) $c->is_primary,
            ]) ?? [],
        ];

        return $this->success($payload);
    }

    // ==========================================
    // 7. CARETAKERS & WARDENS DIRECTORY
    // ==========================================

    /**
     * List hostel caretakers and wardens.
     */
    public function caretakers(Request $request): JsonResponse
    {
        $query = HostelCaretaker::with('building');

        if ($buildingId = $request->input('building_id')) {
            $query->where('hostel_building_id', $buildingId);
        }

        if ($shift = $request->input('shift')) {
            $query->where('shift', $shift);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $caretakers = $query->orderBy('is_primary', 'desc')->orderBy('name')->paginate($request->integer('per_page', 25));

        return $this->success($caretakers);
    }

    /**
     * Show single caretaker details.
     */
    public function showCaretaker(int|string $id): JsonResponse
    {
        $caretaker = HostelCaretaker::with('building')->find($id);
        if (! $caretaker) {
            return $this->error('CARETAKER_NOT_FOUND', "Hostel caretaker with ID '{$id}' was not found.", 404);
        }

        return $this->success($caretaker);
    }

    /**
     * Register a new building caretaker or warden.
     */
    public function storeCaretaker(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'hostel_building_id' => 'required|exists:hostel_buildings,id',
            'name' => 'required|string|max:120',
            'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:120',
            'shift' => 'required|in:day,evening,night,general',
            'is_primary' => 'nullable|boolean',
            'status' => 'nullable|in:active,inactive',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid caretaker payload.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        return DB::transaction(function () use ($validator, $user) {
            $data = $validator->validated();
            $data['created_by'] = $user?->id;
            $data['updated_by'] = $user?->id;

            if (! empty($data['is_primary'])) {
                HostelCaretaker::where('hostel_building_id', $data['hostel_building_id'])
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $caretaker = HostelCaretaker::create($data);

            AuditLog::record(
                action: 'hostel_caretaker_created',
                details: [
                    'caretaker_id' => $caretaker->id,
                    'name' => $caretaker->name,
                    'building_id' => $caretaker->hostel_building_id,
                    'phone' => $caretaker->phone,
                    'shift' => $caretaker->shift,
                ],
                userId: $user?->id,
                auditableType: HostelCaretaker::class,
                auditableId: $caretaker->id,
                description: "Added caretaker {$caretaker->name} to Building ID {$caretaker->hostel_building_id}"
            );

            return $this->success($caretaker->fresh('building'), null, 201);
        });
    }

    /**
     * Update an existing caretaker.
     */
    public function updateCaretaker(Request $request, int|string $id): JsonResponse
    {
        $caretaker = HostelCaretaker::find($id);
        if (! $caretaker) {
            return $this->error('CARETAKER_NOT_FOUND', "Hostel caretaker with ID '{$id}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:120',
            'phone' => 'sometimes|required|string|max:30',
            'email' => 'nullable|email|max:120',
            'shift' => 'sometimes|required|in:day,evening,night,general',
            'is_primary' => 'nullable|boolean',
            'status' => 'sometimes|required|in:active,inactive',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid caretaker update payload.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        return DB::transaction(function () use ($caretaker, $validator, $user) {
            $data = $validator->validated();
            $data['updated_by'] = $user?->id;

            if (! empty($data['is_primary'])) {
                HostelCaretaker::where('hostel_building_id', $caretaker->hostel_building_id)
                    ->where('id', '!=', $caretaker->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $caretaker->update($data);

            AuditLog::record(
                action: 'hostel_caretaker_updated',
                details: [
                    'caretaker_id' => $caretaker->id,
                    'name' => $caretaker->name,
                    'building_id' => $caretaker->hostel_building_id,
                ],
                userId: $user?->id,
                auditableType: HostelCaretaker::class,
                auditableId: $caretaker->id,
                description: "Updated caretaker {$caretaker->name}"
            );

            return $this->success($caretaker->fresh('building'));
        });
    }

    /**
     * Delete / archive a caretaker.
     */
    public function destroyCaretaker(int|string $id): JsonResponse
    {
        $caretaker = HostelCaretaker::find($id);
        if (! $caretaker) {
            return $this->error('CARETAKER_NOT_FOUND', "Hostel caretaker with ID '{$id}' was not found.", 404);
        }

        $caretaker->delete();

        AuditLog::record(
            action: 'hostel_caretaker_deleted',
            details: [
                'caretaker_id' => $caretaker->id,
                'name' => $caretaker->name,
                'building_id' => $caretaker->hostel_building_id,
            ],
            userId: auth()->id(),
            auditableType: HostelCaretaker::class,
            auditableId: $caretaker->id,
            description: "Soft deleted caretaker {$caretaker->name}"
        );

        return $this->success(['message' => 'Caretaker successfully archived.']);
    }

    // ==========================================
    // 8. AVAILABLE ROOMS & STUDENT ROOMMATES
    // ==========================================

    /**
     * Query lightweight vacancy matrix for mobile booking & dropdown selectors.
     */
    public function availableRooms(Request $request): JsonResponse
    {
        $query = HostelRoom::with([
            'building:id,name,code,gender_type',
            'floor:id,floor_number,name',
            'beds' => fn ($q) => $q->where('status', 'available'),
        ])->where('status', '!=', 'maintenance');

        if ($buildingId = $request->input('building_id')) {
            $query->where('hostel_building_id', $buildingId);
        }

        if ($floorId = $request->input('floor_id')) {
            $query->where('hostel_floor_id', $floorId);
        }

        if ($gender = $request->input('gender')) {
            $genderAllowed = match (strtolower($gender)) {
                'male', 'm', 'boy' => ['male_only', 'any'],
                'female', 'f', 'girl', 'woman' => ['female_only', 'any'],
                default => ['any'],
            };
            $query->whereIn('gender_allowed', $genderAllowed);
        }

        if ($roomType = $request->input('room_type')) {
            $query->where('room_type', $roomType);
        }

        $rooms = $query->whereHas('beds', fn ($q) => $q->where('status', 'available'))
            ->get()
            ->map(fn (HostelRoom $r) => [
                'room_id' => $r->id,
                'room_number' => $r->room_number,
                'room_type' => $r->room_type,
                'gender_allowed' => $r->gender_allowed,
                'monthly_fee' => $r->monthly_fee,
                'building' => [
                    'id' => $r->building?->id,
                    'name' => $r->building?->name,
                    'code' => $r->building?->code,
                ],
                'floor' => [
                    'id' => $r->floor?->id,
                    'name' => $r->floor?->name ?? "Floor {$r->floor?->floor_number}",
                ],
                'available_beds_count' => $r->beds->count(),
                'available_beds' => $r->beds->map(fn (HostelBed $b) => [
                    'id' => $b->id,
                    'bed_number' => $b->bed_number,
                ]),
            ]);

        $totalAvailableBeds = $rooms->sum('available_beds_count');

        return $this->success($rooms, [
            'total_available_rooms' => $rooms->count(),
            'total_available_beds' => $totalAvailableBeds,
        ]);
    }

    /**
     * Resident self-service: list current roommates in the same allocated room.
     */
    public function myRoommates(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Resident authentication is required.', 401);
        }

        $myAllocation = HostelAllocation::with(['room.building'])->active()->where('user_id', $user->id)->first();
        if (! $myAllocation) {
            return $this->error('NO_ACTIVE_ALLOCATION', 'You do not have an active hostel accommodation.', 404);
        }

        $roommates = HostelAllocation::with([
            'user.studentProfile',
            'user.activeEnrollment.course',
            'user.activeEnrollment.batch',
            'bed',
        ])
            ->where('hostel_room_id', $myAllocation->hostel_room_id)
            ->where('user_id', '!=', $user->id)
            ->active()
            ->get()
            ->map(fn (HostelAllocation $a) => [
                'resident_id' => $a->user_id,
                'name' => $a->user?->name,
                'email' => $a->user?->email,
                'phone' => $a->user?->phone,
                'course' => $a->user?->activeEnrollment?->course?->name,
                'batch' => $a->user?->activeEnrollment?->batch?->name,
                'bed_number' => $a->bed?->bed_number,
                'since' => $a->start_date?->toDateString(),
            ]);

        return $this->success($roommates, [
            'room_number' => $myAllocation->room?->room_number,
            'building' => $myAllocation->room?->building?->name,
            'total_roommates' => $roommates->count(),
        ]);
    }

    /**
     * Public/Resident catalog of hostel buildings, room categories, amenities, rules & schedules.
     */
    public function catalog(Request $request): JsonResponse
    {
        $buildings = HostelBuilding::with(['floors.rooms.beds'])->active()->orderBy('display_order')->get()->map(fn (HostelBuilding $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'code' => $b->code,
            'gender_type' => $b->gender_type,
            'warden_name' => $b->warden_name,
            'warden_contact' => $b->warden_contact,
            'total_floors' => $b->floors->count(),
            'total_rooms' => $b->floors->sum(fn ($f) => $f->rooms->count()),
            'total_beds' => $b->floors->sum(fn ($f) => $f->rooms->sum(fn ($r) => $r->beds->count())),
            'available_beds' => $b->floors->sum(fn ($f) => $f->rooms->sum(fn ($r) => $r->beds->where('status', 'available')->count())),
        ]);

        $policies = [
            'curfew_time' => Setting::get('hostel.curfew_time', '21:30'),
            'night_rollcall_time' => Setting::get('hostel.night_rollcall_time', '22:00'),
            'visitor_hours' => Setting::get('hostel.visitor_hours', '16:00 - 19:00'),
            'mess_timings' => [
                'breakfast' => Setting::get('hostel.breakfast_time', '07:30 - 09:00'),
                'lunch' => Setting::get('hostel.lunch_time', '12:30 - 14:00'),
                'evening_snacks' => Setting::get('hostel.snacks_time', '17:00 - 18:00'),
                'dinner' => Setting::get('hostel.dinner_time', '20:00 - 21:30'),
            ],
            'rules' => [
                __('Mandatory biometric or card attendance check-in before curfew hours.'),
                __('No unauthorized electrical appliances (heaters, induction cooktops) without prior warden permission.'),
                __('Visitors are strictly restricted to the ground-floor reception visitor lounge.'),
                __('Prior approval required through digital gate pass for overnight leave or absence.'),
            ],
        ];

        return $this->success([
            'buildings' => $buildings,
            'room_types' => [
                ['type' => 'single', 'name' => __('Single Occupancy (Air Conditioned / Study Desk)'), 'capacity' => 1],
                ['type' => 'double', 'name' => __('Double Sharing (Twin Beds / Individual Wardrobes)'), 'capacity' => 2],
                ['type' => 'triple', 'name' => __('Triple Sharing (Spacious Suite / Individual Lockers)'), 'capacity' => 3],
                ['type' => 'dormitory', 'name' => __('Dormitory Hall (Modular Bunk Beds)'), 'capacity' => 6],
            ],
            'standard_amenities' => [
                __('High-Speed Wi-Fi (Dual-Band 5GHz)'),
                __('RO Filtered Drinking Water Dispensers on each floor'),
                __('24x7 Uninterrupted Generator Power Backup'),
                __('24x7 CCTV Security & Biometric Access Control'),
                __('Dedicated Study Hall & Civil Services Discussion Room'),
                __('Daily Housekeeping & Waste Segregation'),
                __('First-Aid Dispensary & On-Call Medical Officer'),
            ],
            'policies' => $policies,
        ]);
    }
}
