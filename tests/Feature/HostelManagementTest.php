<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelCaretaker;
use App\Models\HostelFloor;
use App\Models\HostelRoom;
use App\Models\Role;
use App\Models\Section;
use App\Models\Setting;
use App\Models\StudentProfile;
use App\Models\Team;
use App\Models\User;
use App\Services\Hostel\HostelFormService;
use App\Services\Hostel\HostelImportExportService;
use App\Services\Hostel\HostelService;
use App\Services\RbacService;
use Database\Seeders\HostelSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(HostelSeeder::class);

    // Ensure default team exists
    $this->team = Team::first() ?? Team::create(['name' => 'Main Institute', 'personal_team' => false]);
});

test('hostel seeder populates standard buildings, floors, rooms, and beds', function () {
    expect(HostelBuilding::count())->toBeGreaterThanOrEqual(3)
        ->and(HostelBuilding::where('name', 'Ganga Hostel')->exists())->toBeTrue()
        ->and(HostelBuilding::where('name', 'Mayurakhshi Hostel')->exists())->toBeTrue()
        ->and(HostelBuilding::where('name', 'Teesta Hostel')->exists())->toBeTrue()
        ->and(HostelFloor::count())->toBeGreaterThan(0)
        ->and(HostelRoom::count())->toBeGreaterThan(0)
        ->and(HostelBed::count())->toBeGreaterThan(0);
});

test('hostel permissions exist in rbac service and are assigned to super admin', function () {
    $superAdminRole = Role::where('name', 'Super Administrator')->first();

    expect($superAdminRole)->not->toBeNull()
        ->and($superAdminRole->hasPermissionTo('hostel.view'))->toBeTrue()
        ->and($superAdminRole->hasPermissionTo('hostel.buildings.manage'))->toBeTrue()
        ->and($superAdminRole->hasPermissionTo('hostel.rooms.manage'))->toBeTrue()
        ->and($superAdminRole->hasPermissionTo('hostel.allocations.manage'))->toBeTrue()
        ->and($superAdminRole->hasPermissionTo('hostel.requests.manage'))->toBeTrue()
        ->and($superAdminRole->hasPermissionTo('hostel.reports.view'))->toBeTrue()
        ->and($superAdminRole->hasPermissionTo('hostel.settings.manage'))->toBeTrue();
});

test('room bed counts and occupancy status are correctly calculated', function () {
    $building = HostelBuilding::where('name', 'Ganga Hostel')->first();
    $floor = $building->floors()->first();

    // Create a 3-bed sharing room
    $room = HostelRoom::create([
        'hostel_building_id' => $building->id,
        'hostel_floor_id' => $floor->id,
        'room_number' => 'G-TEST-101',
        'room_type' => 'sharing',
        'capacity' => 3,
        'gender_allowed' => 'male_only',
        'status' => 'available',
        'monthly_charge' => 2500,
    ]);

    $bed1 = HostelBed::create(['hostel_room_id' => $room->id, 'bed_number' => 'Bed-A', 'status' => 'available']);
    $bed2 = HostelBed::create(['hostel_room_id' => $room->id, 'bed_number' => 'Bed-B', 'status' => 'available']);
    $bed3 = HostelBed::create(['hostel_room_id' => $room->id, 'bed_number' => 'Bed-C', 'status' => 'available']);

    expect($room->fresh()->isSharing())->toBeTrue()
        ->and($room->fresh()->availableBedsCount())->toEqual(3)
        ->and($room->fresh()->occupiedBedsCount())->toEqual(0)
        ->and($room->fresh()->isFull())->toBeFalse();

    // Mark bed1 as occupied
    $bed1->update(['status' => 'occupied']);

    expect($room->fresh()->availableBedsCount())->toEqual(2)
        ->and($room->fresh()->occupiedBedsCount())->toEqual(1)
        ->and($room->fresh()->isFull())->toBeFalse();

    // Mark remaining beds occupied
    $bed2->update(['status' => 'occupied']);
    $bed3->update(['status' => 'occupied']);

    expect($room->fresh()->availableBedsCount())->toEqual(0)
        ->and($room->fresh()->occupiedBedsCount())->toEqual(3)
        ->and($room->fresh()->isFull())->toBeTrue();
});

test('gender designation validation prevents cross-gender allocations', function () {
    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first(); // male_only
    $gangaRoom = $gangaBuilding->rooms()->where('gender_allowed', 'male_only')->first();
    $gangaBed = $gangaRoom->beds()->where('status', 'available')->first();

    $femaleStudent = User::factory()->create([
        'name' => 'Sunita Das',
        'email' => 'sunita.das@example.com',
        'gender' => 'Female',
    ]);
    StudentProfile::create([
        'user_id' => $femaleStudent->id,
        'urn' => 'URN-TEST-FEMALE-01',
        'gender' => 'Female',
        'student_status' => 'enrolled',
    ]);

    /** @var HostelService $hostelService */
    $hostelService = app(HostelService::class);

    // Attempt to allocate female student to male-only room should fail
    expect(fn () => $hostelService->allocateResident([
        'user_id' => $femaleStudent->id,
        'hostel_room_id' => $gangaRoom->id,
        'hostel_bed_id' => $gangaBed->id,
        'start_date' => Carbon::now()->format('Y-m-d'),
        'duration_value' => 6,
        'duration_unit' => 'months',
    ]))->toThrow(ValidationException::class);
});

test('service allocates resident with computed timeline and marks bed occupied', function () {
    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first();
    $gangaRoom = $gangaBuilding->rooms()->where('gender_allowed', 'male_only')->first();
    $gangaBed = $gangaRoom->beds()->where('status', 'available')->first();

    $maleStudent = User::factory()->create([
        'name' => 'Rahul Sen',
        'email' => 'rahul.sen@example.com',
        'gender' => 'Male',
    ]);
    StudentProfile::create([
        'user_id' => $maleStudent->id,
        'urn' => 'URN-TEST-MALE-01',
        'gender' => 'Male',
        'student_status' => 'enrolled',
    ]);

    /** @var HostelService $hostelService */
    $hostelService = app(HostelService::class);

    $startDate = Carbon::now()->startOfDay();
    $allocation = $hostelService->allocateResident([
        'user_id' => $maleStudent->id,
        'hostel_room_id' => $gangaRoom->id,
        'hostel_bed_id' => $gangaBed->id,
        'start_date' => $startDate->format('Y-m-d'),
        'duration_value' => 3,
        'duration_unit' => 'months',
        'remarks' => 'Assigned for UPSC Prelims coaching term',
        'hard_copy_form_submitted' => true,
    ]);

    expect($allocation)->toBeInstanceOf(HostelAllocation::class)
        ->and($allocation->status)->toEqual('active')
        ->and($allocation->start_date->format('Y-m-d'))->toEqual($startDate->format('Y-m-d'))
        ->and($allocation->end_date->format('Y-m-d'))->toEqual($startDate->copy()->addMonths(3)->format('Y-m-d'))
        ->and($gangaBed->fresh()->status)->toEqual('occupied')
        ->and($allocation->hard_copy_form_submitted)->toBeTrue();
});

test('previous batch student can be accommodated with historical batch identifier', function () {
    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first();
    $gangaRoom = $gangaBuilding->rooms()->where('gender_allowed', 'male_only')->first();
    $gangaBed = $gangaRoom->beds()->where('status', 'available')->first();

    $seniorStudent = User::factory()->create([
        'name' => 'Vikram Roy',
        'email' => 'vikram.roy@example.com',
        'gender' => 'Male',
    ]);

    /** @var HostelService $hostelService */
    $hostelService = app(HostelService::class);

    $pastDate = Carbon::now()->subYears(2)->startOfDay();
    $allocation = $hostelService->allocateResident([
        'user_id' => $seniorStudent->id,
        'hostel_room_id' => $gangaRoom->id,
        'hostel_bed_id' => $gangaBed->id,
        'is_previous_batch_student' => true,
        'previous_batch_identifier' => 'Batch 2022-2024 (WBCS Gr-A)',
        'start_date' => $pastDate->format('Y-m-d'),
        'duration_value' => 3,
        'duration_unit' => 'years',
        'remarks' => 'Senior resident studying for Mains interview',
    ]);

    expect($allocation->is_previous_batch_student)->toBeTrue()
        ->and($allocation->previous_batch_identifier)->toEqual('Batch 2022-2024 (WBCS Gr-A)')
        ->and($allocation->start_date->format('Y-m-d'))->toEqual($pastDate->format('Y-m-d'))
        ->and($allocation->isActive())->toBeTrue();
});

test('admin can explicitly extend resident stay duration', function () {
    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first();
    $gangaRoom = $gangaBuilding->rooms()->where('gender_allowed', 'male_only')->first();
    $gangaBed = $gangaRoom->beds()->where('status', 'available')->first();

    $resident = User::factory()->create(['name' => 'Sourav Deb', 'email' => 'sourav@example.com', 'gender' => 'Male']);

    /** @var HostelService $hostelService */
    $hostelService = app(HostelService::class);
    $allocation = $hostelService->allocateResident([
        'user_id' => $resident->id,
        'hostel_room_id' => $gangaRoom->id,
        'hostel_bed_id' => $gangaBed->id,
        'start_date' => Carbon::now()->format('Y-m-d'),
        'duration_value' => 30,
        'duration_unit' => 'days',
    ]);

    $originalEndDate = $allocation->end_date->copy();
    $extendedEndDate = $originalEndDate->copy()->addMonths(2);

    $updatedAllocation = $hostelService->extendAllocation(
        $allocation,
        $extendedEndDate->format('Y-m-d'),
        'Approved extension for Special Exam Revision'
    );

    expect($updatedAllocation->end_date->format('Y-m-d'))->toEqual($extendedEndDate->format('Y-m-d'))
        ->and($updatedAllocation->extensionRequests()->count())->toEqual(1)
        ->and($updatedAllocation->extensionRequests()->first()->status)->toEqual('approved');
});

test('resident extension request workflow handles approval and updates allocation end date', function () {
    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first();
    $gangaRoom = $gangaBuilding->rooms()->where('gender_allowed', 'male_only')->first();
    $gangaBed = $gangaRoom->beds()->where('status', 'available')->first();

    $resident = User::factory()->create(['name' => 'Subham Roy', 'email' => 'subham@example.com', 'gender' => 'Male']);

    /** @var HostelService $hostelService */
    $hostelService = app(HostelService::class);
    $allocation = $hostelService->allocateResident([
        'user_id' => $resident->id,
        'hostel_room_id' => $gangaRoom->id,
        'hostel_bed_id' => $gangaBed->id,
        'start_date' => Carbon::now()->format('Y-m-d'),
        'duration_value' => 1,
        'duration_unit' => 'months',
    ]);

    $requestedEndDate = $allocation->end_date->copy()->addMonths(3);

    // Resident submits request
    $request = $hostelService->submitExtensionRequest(
        $allocation,
        $requestedEndDate->format('Y-m-d'),
        'Need to continue stay for upcoming Mock Test Series'
    );

    expect($request->status)->toEqual('pending')
        ->and($request->isPending())->toBeTrue();

    // Admin approves request
    $admin = User::factory()->create(['name' => 'Admin Warden', 'email' => 'warden@example.com']);
    $approvedRequest = $hostelService->approveExtensionRequest($request, $admin, 'Approved on recommendation of Faculty Coordinator');

    expect($approvedRequest->status)->toEqual('approved')
        ->and($approvedRequest->reviewed_by)->toEqual($admin->id)
        ->and($allocation->fresh()->end_date->format('Y-m-d'))->toEqual($requestedEndDate->format('Y-m-d'));
});

test('resident extension request rejection requires mandatory reason', function () {
    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first();
    $gangaRoom = $gangaBuilding->rooms()->where('gender_allowed', 'male_only')->first();
    $gangaBed = $gangaRoom->beds()->where('status', 'available')->first();

    $resident = User::factory()->create(['name' => 'Koushik Paul', 'email' => 'koushik@example.com', 'gender' => 'Male']);

    /** @var HostelService $hostelService */
    $hostelService = app(HostelService::class);
    $allocation = $hostelService->allocateResident([
        'user_id' => $resident->id,
        'hostel_room_id' => $gangaRoom->id,
        'hostel_bed_id' => $gangaBed->id,
        'start_date' => Carbon::now()->format('Y-m-d'),
        'duration_value' => 1,
        'duration_unit' => 'months',
    ]);

    $request = $hostelService->submitExtensionRequest(
        $allocation,
        $allocation->end_date->copy()->addMonths(1)->format('Y-m-d'),
        'Extension requested'
    );

    $admin = User::factory()->create(['name' => 'Admin Warden', 'email' => 'warden2@example.com']);

    // Empty rejection reason must throw ValidationException
    expect(fn () => $hostelService->rejectExtensionRequest($request, $admin, ''))
        ->toThrow(ValidationException::class);

    // Valid rejection with reason
    $rejectedRequest = $hostelService->rejectExtensionRequest($request, $admin, 'Room undergoing renovation next month');

    expect($rejectedRequest->status)->toEqual('rejected')
        ->and($rejectedRequest->rejection_reason)->toEqual('Room undergoing renovation next month');
});

test('extension request is blocked when admin setting disables resident extension requests', function () {
    Setting::set('hostel.allow_resident_extension_requests', '0');

    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first();
    $gangaRoom = $gangaBuilding->rooms()->where('gender_allowed', 'male_only')->first();
    $gangaBed = $gangaRoom->beds()->where('status', 'available')->first();

    $resident = User::factory()->create(['name' => 'Aniket Ghosh', 'email' => 'aniket@example.com', 'gender' => 'Male']);

    /** @var HostelService $hostelService */
    $hostelService = app(HostelService::class);
    $allocation = $hostelService->allocateResident([
        'user_id' => $resident->id,
        'hostel_room_id' => $gangaRoom->id,
        'hostel_bed_id' => $gangaBed->id,
        'start_date' => Carbon::now()->format('Y-m-d'),
        'duration_value' => 2,
        'duration_unit' => 'months',
    ]);

    expect(fn () => $hostelService->submitExtensionRequest(
        $allocation,
        $allocation->end_date->copy()->addMonths(1)->format('Y-m-d'),
        'Request stay extension'
    ))->toThrow(ValidationException::class);
});

test('vacating allocation marks allocation checked_out and releases bed to available', function () {
    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first();
    $gangaRoom = $gangaBuilding->rooms()->where('gender_allowed', 'male_only')->first();
    $gangaBed = $gangaRoom->beds()->where('status', 'available')->first();

    $resident = User::factory()->create(['name' => 'Manish Paul', 'email' => 'manish@example.com', 'gender' => 'Male']);

    /** @var HostelService $hostelService */
    $hostelService = app(HostelService::class);
    $allocation = $hostelService->allocateResident([
        'user_id' => $resident->id,
        'hostel_room_id' => $gangaRoom->id,
        'hostel_bed_id' => $gangaBed->id,
        'start_date' => Carbon::now()->format('Y-m-d'),
        'duration_value' => 6,
        'duration_unit' => 'months',
    ]);

    expect($gangaBed->fresh()->status)->toEqual('occupied');

    $vacatedAllocation = $hostelService->vacateAllocation($allocation, 'Course completed and clearance verified');

    expect($vacatedAllocation->status)->toEqual('checked_out')
        ->and($vacatedAllocation->vacated_at)->not->toBeNull()
        ->and($gangaBed->fresh()->status)->toEqual('available');
});

test('hostel livewire admin components render and load without error', function () {
    $admin = User::factory()->create(['name' => 'Super Admin User', 'email' => 'admin@example.com']);
    $admin->assignRole('Super Administrator');
    $this->actingAs($admin);

    Livewire::test('pages::admin.hostel.dashboard')
        ->assertStatus(200)
        ->assertSee('Ganga Hostel')
        ->assertSee('Mayurakhshi Hostel');

    Livewire::test('pages::admin.hostel.rooms-board')
        ->assertStatus(200)
        ->assertSee('Visual Room');

    Livewire::test('pages::admin.hostel.buildings')
        ->assertStatus(200)
        ->assertSee('Hostel Buildings');

    Livewire::test('pages::admin.hostel.rooms')
        ->assertStatus(200)
        ->assertSee('Hostel Rooms');

    Livewire::test('pages::admin.hostel.allocations')
        ->assertStatus(200)
        ->assertSee('Hostel Room Allocations');

    Livewire::test('pages::admin.hostel.requests')
        ->assertStatus(200)
        ->assertSee('Accommodation Extension Requests');

    Livewire::test('pages::admin.hostel.reports')
        ->assertStatus(200)
        ->assertSee('Hostel Reports');

    Livewire::test('pages::admin.settings.hostel')
        ->assertStatus(200)
        ->assertSee('Resident Extension Requests');
});

test('student hostel portal renders resident active stay details', function () {
    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first();
    $gangaRoom = $gangaBuilding->rooms()->where('gender_allowed', 'male_only')->first();
    $gangaBed = $gangaRoom->beds()->where('status', 'available')->first();

    $student = User::factory()->create([
        'name' => 'Pritam Das',
        'email' => 'pritam.das@example.com',
        'gender' => 'Male',
    ]);
    StudentProfile::create([
        'user_id' => $student->id,
        'urn' => 'URN-TEST-STUDENT-99',
        'gender' => 'Male',
        'student_status' => 'enrolled',
    ]);
    $student->syncRoles(['Student']);

    /** @var HostelService $hostelService */
    $hostelService = app(HostelService::class);
    $hostelService->allocateResident([
        'user_id' => $student->id,
        'hostel_room_id' => $gangaRoom->id,
        'hostel_bed_id' => $gangaBed->id,
        'start_date' => Carbon::now()->format('Y-m-d'),
        'duration_value' => 6,
        'duration_unit' => 'months',
        'remarks' => 'Assigned bed in Room G-101',
    ]);

    $this->actingAs($student);

    Livewire::test('pages::student.hostel')
        ->assertStatus(200)
        ->assertSee($gangaBuilding->name)
        ->assertSee($gangaRoom->room_number)
        ->assertSee($gangaBed->bed_number);
});

test('building manager details are persisted and seeded correctly', function () {
    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first();

    expect($gangaBuilding->manager_name)->toEqual('Mr. Biplab Karmakar')
        ->and($gangaBuilding->manager_contact)->toEqual('+91 98305 11223')
        ->and($gangaBuilding->manager_email)->toEqual('manager.ganga@sntcssc.edu.in');

    $gangaBuilding->update([
        'manager_name' => 'Mr. Alok Mukherjee',
        'manager_contact' => '+91 94320 00000',
    ]);

    expect($gangaBuilding->fresh()->manager_name)->toEqual('Mr. Alok Mukherjee')
        ->and($gangaBuilding->fresh()->manager_contact)->toEqual('+91 94320 00000');
});

test('hostel building supports multiple caretakers across different shifts with contact details and primary designation', function () {
    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first();

    // Check seeded caretakers
    expect($gangaBuilding->caretakers()->count())->toBeGreaterThanOrEqual(2);

    // Create another caretaker for night shift
    $caretaker = HostelCaretaker::create([
        'hostel_building_id' => $gangaBuilding->id,
        'name' => 'Bimal Chandra Roy',
        'phone' => '+91 98319 88888',
        'email' => 'bimal.roy@sntcssc.in',
        'shift' => 'night',
        'is_primary' => false,
        'status' => 'active',
        'notes' => 'Campus night watch & emergency key holder',
    ]);

    expect($caretaker->id)->toBeGreaterThan(0)
        ->and($caretaker->building->id)->toEqual($gangaBuilding->id)
        ->and($caretaker->shift_label)->toEqual('Night Shift (10 PM - 6 AM)')
        ->and($gangaBuilding->activeCaretakers()->where('id', $caretaker->id)->exists())->toBeTrue();
});

test('hostel admission form service generates valid pdf prefilled with student and enrollment data', function () {
    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first();
    $gangaRoom = $gangaBuilding->rooms()->where('gender_allowed', 'male_only')->first();
    $gangaBed = $gangaRoom->beds()->where('status', 'available')->first();

    $student = User::factory()->create([
        'name' => 'Anupam Roy',
        'email' => 'anupam.roy@example.com',
        'gender' => 'Male',
        'phone' => '+91 98300 11111',
    ]);
    StudentProfile::create([
        'user_id' => $student->id,
        'urn' => 'URN-2026-AR-001',
        'gender' => 'Male',
        'student_status' => 'enrolled',
    ]);

    $course = Course::create([
        'name' => 'UPSC Civil Services Examination Coaching',
        'code' => 'UPSC-CSE-2026',
        'category' => 'Coaching',
        'fee' => 5000,
        'status' => 'active',
    ]);

    $batch = Batch::create([
        'name' => 'UPSC Batch Alpha 2026',
        'code' => 'UPSC-ALPHA-26',
        'academic_year' => '2026-2027',
        'status' => 'active',
    ]);

    $section = Section::create([
        'batch_id' => $batch->id,
        'course_id' => $course->id,
        'name' => 'Section A (General Studies)',
        'code' => 'SEC-A-GS',
        'capacity' => 60,
        'status' => 'active',
    ]);

    Enrollment::create([
        'enrollment_no' => 'ENR-2026-0099',
        'user_id' => $student->id,
        'student_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'section_id' => $section->id,
        'enrolled_at' => Carbon::now(),
        'status' => 'active',
        'fee_amount' => 5000,
        'fee_status' => 'paid',
    ]);

    /** @var HostelService $hostelService */
    $hostelService = app(HostelService::class);
    $allocation = $hostelService->allocateResident([
        'user_id' => $student->id,
        'hostel_room_id' => $gangaRoom->id,
        'hostel_bed_id' => $gangaBed->id,
        'start_date' => Carbon::now()->format('Y-m-d'),
        'duration_value' => 6,
        'duration_unit' => 'months',
        'remarks' => 'Full-time residential IAS aspirant',
    ]);

    // Verify dynamic accessors pull correct academic details
    $expectedId = (string) ($student->fresh()->student_id ?: $student->studentProfile?->urn ?: 'URN-2026-AR-001');
    expect($allocation->student_identifier)->toEqual($expectedId)
        ->and($allocation->enrollment_no)->toEqual('ENR-2026-0099')
        ->and($allocation->course_name)->toEqual('UPSC Civil Services Examination Coaching')
        ->and($allocation->batch_name)->toEqual('UPSC Batch Alpha 2026')
        ->and($allocation->section_name)->toEqual('Section A (General Studies)');

    // Generate PDF via HostelFormService
    $pdf = HostelFormService::generatePersonalizedFormPdf($allocation);
    $pdfBinary = $pdf->output();

    expect(is_string($pdfBinary))->toBeTrue()
        ->and(strlen($pdfBinary))->toBeGreaterThan(500)
        ->and(str_starts_with($pdfBinary, '%PDF-'))->toBeTrue();
});

test('student can download hostel admission form via web route respecting mode settings', function () {
    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first();
    $gangaRoom = $gangaBuilding->rooms()->where('gender_allowed', 'male_only')->first();
    $gangaBed = $gangaRoom->beds()->where('status', 'available')->first();

    $student = User::factory()->create([
        'name' => 'Debjit Sen',
        'email' => 'debjit.sen@example.com',
        'gender' => 'Male',
    ]);
    StudentProfile::create([
        'user_id' => $student->id,
        'urn' => 'URN-2026-DS-002',
        'gender' => 'Male',
        'student_status' => 'enrolled',
    ]);
    $student->syncRoles(['Student']);

    /** @var HostelService $hostelService */
    $hostelService = app(HostelService::class);
    $hostelService->allocateResident([
        'user_id' => $student->id,
        'hostel_room_id' => $gangaRoom->id,
        'hostel_bed_id' => $gangaBed->id,
        'start_date' => Carbon::now()->format('Y-m-d'),
        'duration_value' => 6,
        'duration_unit' => 'months',
    ]);

    $this->actingAs($student);

    // Test 1: Generated personalized mode
    Setting::set('hostel.form_download_mode', 'generated');
    $response = $this->get(route('hostel.form.download'));

    $response->assertStatus(200)
        ->assertHeader('content-type', 'application/pdf');

    // Test 2: Uploaded custom PDF mode
    Storage::fake('public');
    $customPdfPath = 'hostel/forms/custom-form.pdf';
    Storage::disk('public')->put($customPdfPath, '%PDF-1.4 Mock Uploaded PDF Content');

    Setting::set('hostel.form_download_mode', 'uploaded');
    Setting::set('hostel.custom_form_path', $customPdfPath);

    $uploadedResponse = $this->get(route('hostel.form.download'));
    $uploadedResponse->assertStatus(200)
        ->assertHeader('content-type', 'application/pdf');
});

test('hostel import and export service handles templates, csv exports and xlsx exports', function () {
    $admin = User::factory()->create(['name' => 'Super Admin User', 'email' => 'admin_export@example.com']);
    $admin->assignRole('Super Administrator');
    $this->actingAs($admin);

    // Download Sample Template
    $sampleResponse = HostelImportExportService::downloadSampleTemplate();
    expect($sampleResponse->getStatusCode())->toEqual(200);

    // Export CSV
    $csvResponse = HostelImportExportService::export('csv');
    expect($csvResponse->getStatusCode())->toEqual(200);

    // Export XLSX
    $xlsxResponse = HostelImportExportService::export('xlsx');
    expect($xlsxResponse->getStatusCode())->toEqual(200);
});

test('hostel import service batch imports residents from csv', function () {
    $gangaBuilding = HostelBuilding::where('name', 'Ganga Hostel')->first();
    $gangaRoom = $gangaBuilding->rooms()->where('gender_allowed', 'male_only')->first();
    $gangaBed = $gangaRoom->beds()->where('status', 'available')->first();

    $student = User::factory()->create([
        'name' => 'Amit Ghosh',
        'email' => 'amit.ghosh.import@example.com',
        'gender' => 'Male',
    ]);
    StudentProfile::create([
        'user_id' => $student->id,
        'urn' => 'URN-IMPORT-001',
        'gender' => 'Male',
        'student_status' => 'enrolled',
    ]);

    $csvContent = "student_email,building_name,room_number,bed_number,start_date,duration_value,duration_unit,is_previous_batch,previous_batch_identifier,remarks,hard_copy_form_submitted\n";
    $csvContent .= "{$student->email},{$gangaBuilding->name},{$gangaRoom->room_number},{$gangaBed->bed_number},".Carbon::now()->format('Y-m-d').",6,months,0,,Imported Resident,1\n";

    $file = UploadedFile::fake()->createWithContent('allocations_import.csv', $csvContent);

    $result = HostelImportExportService::importAllocations($file, auth()->id() ?? 1);

    expect($result['success'])->toBeGreaterThanOrEqual(1)
        ->and(count($result['errors']))->toEqual(0)
        ->and($gangaBed->fresh()->status)->toEqual('occupied')
        ->and(HostelAllocation::where('user_id', $student->id)->where('status', 'active')->exists())->toBeTrue();
});

test('hostel allocation export handles landscape pdf format', function () {
    $admin = User::factory()->create(['name' => 'Admin PDF User', 'email' => 'admin_pdf@example.com']);
    $admin->assignRole('Super Administrator');
    $this->actingAs($admin);

    $pdfResponse = HostelImportExportService::export(null, 'pdf');
    expect($pdfResponse->getStatusCode())->toEqual(200)
        ->and($pdfResponse->headers->get('content-type'))->toContain('application/pdf');

    ob_start();
    $pdfResponse->sendContent();
    $binary = ob_get_clean();
    expect(str_starts_with($binary, '%PDF-'))->toBeTrue();
});

test('buildings and floors export handles xlsx, csv and pdf formats', function () {
    $admin = User::factory()->create(['name' => 'Admin Buildings', 'email' => 'admin_bldg@example.com']);
    $admin->assignRole('Super Administrator');
    $this->actingAs($admin);

    $template = HostelImportExportService::downloadBuildingsSampleTemplate();
    expect($template->getStatusCode())->toEqual(200);

    $csv = HostelImportExportService::exportBuildings('csv');
    expect($csv->getStatusCode())->toEqual(200);

    $xlsx = HostelImportExportService::exportBuildings('xlsx');
    expect($xlsx->getStatusCode())->toEqual(200);

    $pdf = HostelImportExportService::exportBuildings('pdf');
    expect($pdf->getStatusCode())->toEqual(200)
        ->and($pdf->headers->get('content-type'))->toContain('application/pdf');
});

test('buildings batch import creates building and provisions floors', function () {
    $csvContent = "name,code,gender_designation,total_floors,status,manager_name,manager_phone,manager_email,address\n";
    $csvContent .= "Rupnarayan Hostel,RN-HOSTEL,coed,3,active,Mr. A. Banerjee,9876543210,banerjee@example.com,ATI Hostel Block D\n";

    $file = UploadedFile::fake()->createWithContent('buildings.csv', $csvContent);
    $res = HostelImportExportService::importBuildings($file);

    expect($res['success'])->toEqual(1)
        ->and(HostelBuilding::where('code', 'RN-HOSTEL')->exists())->toBeTrue();

    $bldg = HostelBuilding::where('code', 'RN-HOSTEL')->first();
    expect($bldg->floors()->count())->toEqual(3)
        ->and($bldg->manager_name)->toEqual('Mr. A. Banerjee');
});

test('rooms and beds export handles xlsx, csv and pdf with active filters', function () {
    $admin = User::factory()->create(['name' => 'Admin Rooms', 'email' => 'admin_rooms@example.com']);
    $admin->assignRole('Super Administrator');
    $this->actingAs($admin);

    $template = HostelImportExportService::downloadRoomsSampleTemplate();
    expect($template->getStatusCode())->toEqual(200);

    $csv = HostelImportExportService::exportRooms(['status' => 'available'], 'csv');
    expect($csv->getStatusCode())->toEqual(200);

    $xlsx = HostelImportExportService::exportRooms(['gender_allowed' => 'male_only'], 'xlsx');
    expect($xlsx->getStatusCode())->toEqual(200);

    $pdf = HostelImportExportService::exportRooms([], 'pdf');
    expect($pdf->getStatusCode())->toEqual(200)
        ->and($pdf->headers->get('content-type'))->toContain('application/pdf');
});

test('rooms batch import provisions room and beds accurately', function () {
    $bldg = HostelBuilding::first();
    $csvContent = "building_code,floor_number,room_number,room_type,capacity,gender_allowed,status,monthly_charge,amenities\n";
    $csvContent .= "{$bldg->code},1,IMPORT-RM-99,sharing,4,male_only,available,1800,AC;WiFi\n";

    $file = UploadedFile::fake()->createWithContent('rooms.csv', $csvContent);
    $res = HostelImportExportService::importRooms($file);

    expect($res['success'])->toEqual(1);
    $room = HostelRoom::where('room_number', 'IMPORT-RM-99')->first();
    expect($room)->not->toBeNull()
        ->and($room->capacity)->toEqual(4)
        ->and($room->beds()->count())->toEqual(4);
});

test('hostel reports export matrix, compliance, and expiring stays in multiple formats', function () {
    $admin = User::factory()->create(['name' => 'Admin Reports', 'email' => 'admin_rep@example.com']);
    $admin->assignRole('Super Administrator');
    $this->actingAs($admin);

    // Matrix
    $matrixData = HostelBuilding::with(['floors.rooms.beds'])->get()->map(function ($b) {
        return [
            'building' => $b->name,
            'code' => $b->code,
            'floors_count' => $b->floors->count(),
            'rooms_count' => $b->rooms()->count(),
            'total_capacity' => (int) $b->rooms()->sum('capacity'),
            'occupied_beds' => (int) $b->rooms()->withCount(['beds' => fn ($q) => $q->where('status', 'occupied')])->get()->sum('beds_count'),
            'available_beds' => (int) $b->rooms()->withCount(['beds' => fn ($q) => $q->where('status', 'available')])->get()->sum('beds_count'),
        ];
    });

    expect(HostelImportExportService::exportReportsMatrix($matrixData, 'csv')->getStatusCode())->toEqual(200)
        ->and(HostelImportExportService::exportReportsMatrix($matrixData, 'xlsx')->getStatusCode())->toEqual(200)
        ->and(HostelImportExportService::exportReportsMatrix($matrixData, 'pdf')->getStatusCode())->toEqual(200);

    // Compliance
    expect(HostelImportExportService::exportReportsCompliance('csv')->getStatusCode())->toEqual(200)
        ->and(HostelImportExportService::exportReportsCompliance('xlsx')->getStatusCode())->toEqual(200)
        ->and(HostelImportExportService::exportReportsCompliance('pdf')->getStatusCode())->toEqual(200);

    // Expiring stays
    expect(HostelImportExportService::exportReportsExpiring('csv')->getStatusCode())->toEqual(200)
        ->and(HostelImportExportService::exportReportsExpiring('xlsx')->getStatusCode())->toEqual(200)
        ->and(HostelImportExportService::exportReportsExpiring('pdf')->getStatusCode())->toEqual(200);
});

test('buildings, rooms, and reports livewire components can trigger export and template download', function () {
    $admin = User::factory()->create(['name' => 'Admin Livewire', 'email' => 'admin_livewire@example.com']);
    $admin->assignRole('Super Administrator');
    $this->actingAs($admin);

    // Buildings export & template
    Livewire::test('pages::admin.hostel.buildings')
        ->call('export', 'csv')
        ->assertFileDownloaded()
        ->call('downloadTemplate')
        ->assertFileDownloaded();

    // Rooms export & template
    Livewire::test('pages::admin.hostel.rooms')
        ->call('export', 'csv')
        ->assertFileDownloaded()
        ->call('downloadTemplate')
        ->assertFileDownloaded();

    // Reports exports
    Livewire::test('pages::admin.hostel.reports')
        ->call('exportMatrix', 'csv')
        ->assertFileDownloaded()
        ->call('exportCompliance', 'csv')
        ->assertFileDownloaded()
        ->call('exportExpiring', 'csv')
        ->assertFileDownloaded();
});

test('students cannot access administrative hostel routes and their sidebar excludes admin desks', function () {
    $student = User::factory()->create([
        'name' => 'Sidebar Test Student',
        'email' => 'sidebar_student@example.com',
    ]);
    StudentProfile::create([
        'user_id' => $student->id,
        'urn' => 'URN-TEST-SB-01',
        'gender' => 'Male',
        'student_status' => 'enrolled',
    ]);
    $student->syncRoles(['Student']);
    $team = $student->currentTeam ?? $this->team;

    $this->actingAs($student);

    // 1. Administrative hostel routes must return 403 Forbidden
    $this->get(route('admin.hostel.dashboard', ['current_team' => $team->slug]))->assertStatus(403);
    $this->get(route('admin.hostel.rooms.board', ['current_team' => $team->slug]))->assertStatus(403);
    $this->get(route('admin.hostel.buildings', ['current_team' => $team->slug]))->assertStatus(403);
    $this->get(route('admin.hostel.rooms.index', ['current_team' => $team->slug]))->assertStatus(403);
    $this->get(route('admin.hostel.allocations', ['current_team' => $team->slug]))->assertStatus(403);
    $this->get(route('admin.hostel.requests', ['current_team' => $team->slug]))->assertStatus(403);
    $this->get(route('admin.hostel.reports', ['current_team' => $team->slug]))->assertStatus(403);
    $this->get(route('admin.settings.hostel', ['current_team' => $team->slug]))->assertStatus(403);
    $this->get(route('admin.user-activity.index', ['current_team' => $team->slug]))->assertStatus(403);

    // 2. Student CAN access student hostel portal
    $this->get(route('student.hostel.index', ['current_team' => $team->slug]))->assertStatus(200);

    // 3. Student dashboard renders and sidebar does not contain administrative hostel or user access sections
    $dashboardResponse = $this->get(route('dashboard', ['current_team' => $team->slug]));
    $dashboardResponse->assertStatus(200);
    $dashboardContent = $dashboardResponse->getContent();

    expect($dashboardContent)->not->toContain(route('admin.hostel.dashboard'))
        ->and($dashboardContent)->not->toContain(route('admin.hostel.rooms.board'))
        ->and($dashboardContent)->not->toContain(route('admin.hostel.buildings'))
        ->and($dashboardContent)->not->toContain(route('admin.user-activity.index'))
        ->and($dashboardContent)->not->toContain(route('student.hostel.index'));

    // 4. When student has hostel allocation history, their dashboard includes student hostel portal
    $room = HostelRoom::first();
    $bed = HostelBed::first();
    HostelAllocation::create([
        'user_id' => $student->id,
        'resident_type' => 'student',
        'hostel_building_id' => $room->hostel_building_id,
        'hostel_room_id' => $room->id,
        'hostel_bed_id' => $bed->id,
        'allocation_no' => 'HST-TEST-SB-01',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonths(6)->toDateString(),
        'status' => 'active',
    ]);

    $allocatedDashboardResponse = $this->get(route('dashboard', ['current_team' => $team->slug]));
    $allocatedDashboardContent = $allocatedDashboardResponse->getContent();
    expect($allocatedDashboardContent)->toContain(route('student.hostel.index'));
});

test('staff user with hostel permissions has access to operations and correct sidebar items', function () {
    $staff = User::factory()->create([
        'name' => 'Hostel Caretaker Staff',
        'email' => 'caretaker_staff@example.com',
    ]);
    $staff->syncRoles(['Staff']);
    $team = $staff->currentTeam ?? $this->team;

    $this->actingAs($staff);

    // Staff can access operational hostel desks
    $this->get(route('admin.hostel.dashboard', ['current_team' => $team->slug]))->assertStatus(200);
    $this->get(route('admin.hostel.rooms.board', ['current_team' => $team->slug]))->assertStatus(200);
    $this->get(route('admin.hostel.allocations', ['current_team' => $team->slug]))->assertStatus(200);
    $this->get(route('admin.hostel.requests', ['current_team' => $team->slug]))->assertStatus(200);
    $this->get(route('admin.hostel.reports', ['current_team' => $team->slug]))->assertStatus(200);

    // Staff cannot access building/floor master creation without buildings.manage permission
    $this->get(route('admin.hostel.buildings', ['current_team' => $team->slug]))->assertStatus(403);
    $this->get(route('admin.settings.hostel', ['current_team' => $team->slug]))->assertStatus(403);

    // Staff dashboard renders admin view
    $this->get(route('dashboard', ['current_team' => $team->slug]))->assertStatus(200);
});

test('candidate or general user without roles renders user dashboard and unauthorized form access is forbidden', function () {
    $candidate = User::factory()->create([
        'name' => 'Applicant Candidate',
        'email' => 'candidate_applicant@example.com',
    ]);
    $team = $candidate->currentTeam ?? $this->team;

    $this->actingAs($candidate);

    // General user dashboard renders with User Dashboard
    $resp = $this->get(route('dashboard', ['current_team' => $team->slug]));
    $resp->assertStatus(200);
    expect($resp->getContent())->toContain(__('User Dashboard'));

    // Attempting to download another resident's allocation form returns 403 Forbidden
    $otherResident = User::factory()->create(['name' => 'Other Resident']);
    $room = HostelRoom::first();
    $bed = HostelBed::first();
    $otherAllocation = HostelAllocation::create([
        'user_id' => $otherResident->id,
        'resident_type' => 'student',
        'hostel_room_id' => $room->id,
        'hostel_bed_id' => $bed->id,
        'allocation_no' => 'HST-TEST-OTH-01',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonths(6)->toDateString(),
        'status' => 'allocated',
    ]);

    $this->get(route('hostel.form.download', ['allocation_id' => $otherAllocation->id]))
        ->assertStatus(403);
});
