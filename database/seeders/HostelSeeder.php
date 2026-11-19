<?php

namespace Database\Seeders;

use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelCaretaker;
use App\Models\HostelFloor;
use App\Models\HostelRoom;
use App\Models\Setting;
use App\Models\User;
use App\Services\Hostel\HostelService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HostelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Seed Settings
            $settings = [
                [
                    'key' => 'hostel.form_download_mode',
                    'value' => 'generated',
                    'group' => 'hostel',
                    'type' => Setting::TYPE_STRING,
                    'label' => 'Hostel Form Download Mode (generated or uploaded)',
                ],
                [
                    'key' => 'hostel.allow_resident_extension_requests',
                    'value' => true,
                    'group' => 'hostel',
                    'type' => Setting::TYPE_BOOLEAN,
                    'label' => 'Allow Residents to Submit Accommodation Extension Requests',
                ],
                [
                    'key' => 'hostel.soft_copy_required',
                    'value' => false,
                    'group' => 'hostel',
                    'type' => Setting::TYPE_BOOLEAN,
                    'label' => 'Require Soft-Copy Hostel Admission Form Upload (Mandatory)',
                ],
                [
                    'key' => 'hostel.default_duration_months',
                    'value' => 6,
                    'group' => 'hostel',
                    'type' => Setting::TYPE_NUMBER,
                    'label' => 'Default Room Accommodation Timeline Duration (Months)',
                ],
                [
                    'key' => 'hostel.expiry_notice_days',
                    'value' => 15,
                    'group' => 'hostel',
                    'type' => Setting::TYPE_NUMBER,
                    'label' => 'Hostel Accommodation Expiration Alert Window (Days)',
                ],
                [
                    'key' => 'hostel.rules_notice',
                    'value' => 'Residents must adhere to hostel code of conduct. Quiet hours are 10:00 PM to 6:00 AM. Visitors permitted only in common reception area.',
                    'group' => 'hostel',
                    'type' => Setting::TYPE_TEXT,
                    'label' => 'Hostel Resident Guidelines and Terms Notice',
                ],
            ];

            foreach ($settings as $s) {
                Setting::firstOrCreate(['key' => $s['key']], $s);
            }

            // 2. Seed Buildings
            $ganga = HostelBuilding::updateOrCreate(
                ['code' => 'GH'],
                [
                    'name' => 'Ganga Hostel',
                    'gender_type' => 'boys_only',
                    'total_floors' => 4,
                    'warden_name' => 'Prof. S. K. Banerjee',
                    'warden_contact' => '+91 98301 23456',
                    'warden_email' => 'warden.ganga@sntcssc.edu.in',
                    'manager_name' => 'Mr. Biplab Karmakar',
                    'manager_contact' => '+91 98305 11223',
                    'manager_email' => 'manager.ganga@sntcssc.edu.in',
                    'description' => 'Dedicated Boys Residential Hostel with study halls, Wi-Fi, and 24/7 power backup.',
                    'status' => 'active',
                    'display_order' => 1,
                ]
            );

            // Caretakers for Ganga Hostel
            HostelCaretaker::firstOrCreate(
                ['hostel_building_id' => $ganga->id, 'phone' => '+91 98311 44556'],
                [
                    'name' => 'Ramesh Chandra Das',
                    'email' => 'caretaker1.ganga@sntcssc.edu.in',
                    'shift' => 'Day Shift (6:00 AM - 2:00 PM)',
                    'is_primary' => true,
                    'status' => 'active',
                    'notes' => 'Main Gate Security & Key Registry Desk',
                ]
            );
            HostelCaretaker::firstOrCreate(
                ['hostel_building_id' => $ganga->id, 'phone' => '+91 98311 55667'],
                [
                    'name' => 'Subal Naskar',
                    'email' => 'caretaker2.ganga@sntcssc.edu.in',
                    'shift' => 'Evening Shift (2:00 PM - 10:00 PM)',
                    'is_primary' => false,
                    'status' => 'active',
                    'notes' => 'Floor 1-3 Maintenance & Study Hall Supervision',
                ]
            );
            HostelCaretaker::firstOrCreate(
                ['hostel_building_id' => $ganga->id, 'phone' => '+91 98311 66778'],
                [
                    'name' => 'Tapan Mondal',
                    'email' => 'caretaker3.ganga@sntcssc.edu.in',
                    'shift' => 'Night Shift (10:00 PM - 6:00 AM)',
                    'is_primary' => false,
                    'status' => 'active',
                    'notes' => 'Night Emergency Assistance & Gate Closure Desk',
                ]
            );

            $mayurakhshi = HostelBuilding::updateOrCreate(
                ['code' => 'MH'],
                [
                    'name' => 'Mayurakhshi Hostel',
                    'gender_type' => 'girls_only',
                    'total_floors' => 3,
                    'warden_name' => 'Dr. Ananya Sen',
                    'warden_contact' => '+91 98302 34567',
                    'warden_email' => 'warden.mayurakhshi@sntcssc.edu.in',
                    'manager_name' => 'Mrs. Sumana Roy',
                    'manager_contact' => '+91 98305 22334',
                    'manager_email' => 'manager.mayurakhshi@sntcssc.edu.in',
                    'description' => 'Dedicated Girls Residential Hostel featuring biometric security, library annex, and infirmary.',
                    'status' => 'active',
                    'display_order' => 2,
                ]
            );

            // Caretakers for Mayurakhshi Hostel
            HostelCaretaker::firstOrCreate(
                ['hostel_building_id' => $mayurakhshi->id, 'phone' => '+91 98312 11223'],
                [
                    'name' => 'Meena Halder',
                    'email' => 'caretaker1.mayurakhshi@sntcssc.edu.in',
                    'shift' => 'Day Shift (6:00 AM - 2:00 PM)',
                    'is_primary' => true,
                    'status' => 'active',
                    'notes' => 'Reception Desk, Visitor Log & First Aid Care',
                ]
            );
            HostelCaretaker::firstOrCreate(
                ['hostel_building_id' => $mayurakhshi->id, 'phone' => '+91 98312 22334'],
                [
                    'name' => 'Aparna Ghosh',
                    'email' => 'caretaker2.mayurakhshi@sntcssc.edu.in',
                    'shift' => 'Evening Shift (2:00 PM - 10:00 PM)',
                    'is_primary' => false,
                    'status' => 'active',
                    'notes' => 'Hostel Library & Common Facilities',
                ]
            );
            HostelCaretaker::firstOrCreate(
                ['hostel_building_id' => $mayurakhshi->id, 'phone' => '+91 98312 33445'],
                [
                    'name' => 'Shanti Barman',
                    'email' => 'caretaker3.mayurakhshi@sntcssc.edu.in',
                    'shift' => 'Night Shift (10:00 PM - 6:00 AM)',
                    'is_primary' => false,
                    'status' => 'active',
                    'notes' => 'Night Biometric Gate & Warden Liaison',
                ]
            );

            $teesta = HostelBuilding::updateOrCreate(
                ['code' => 'TH'],
                [
                    'name' => 'Teesta Hostel',
                    'gender_type' => 'coed',
                    'total_floors' => 2,
                    'warden_name' => 'Dr. R. K. Mukherjee',
                    'warden_contact' => '+91 98303 45678',
                    'warden_email' => 'warden.teesta@sntcssc.edu.in',
                    'manager_name' => 'Mr. Debasis Roy',
                    'manager_contact' => '+91 98305 33445',
                    'manager_email' => 'manager.teesta@sntcssc.edu.in',
                    'description' => 'Executive & Faculty Residence with separated male and female accommodation wings.',
                    'status' => 'active',
                    'display_order' => 3,
                ]
            );

            // Caretakers for Teesta Hostel
            HostelCaretaker::firstOrCreate(
                ['hostel_building_id' => $teesta->id, 'phone' => '+91 98313 11223'],
                [
                    'name' => 'Prabir Sen',
                    'email' => 'caretaker1.teesta@sntcssc.edu.in',
                    'shift' => 'Rotating Shift (Day/Evening)',
                    'is_primary' => true,
                    'status' => 'active',
                    'notes' => 'Executive Wing Supervisor & Concierge Desk',
                ]
            );
            HostelCaretaker::firstOrCreate(
                ['hostel_building_id' => $teesta->id, 'phone' => '+91 98313 22334'],
                [
                    'name' => 'Kanika Dey',
                    'email' => 'caretaker2.teesta@sntcssc.edu.in',
                    'shift' => 'Day Shift (8:00 AM - 4:00 PM)',
                    'is_primary' => false,
                    'status' => 'active',
                    'notes' => 'Faculty Wing Care & Housekeeping Audit',
                ]
            );

            // 3. Seed Floors & Rooms for Ganga Hostel (Boys Only)
            $floorNames = [
                0 => 'Ground Floor',
                1 => '1st Floor',
                2 => '2nd Floor',
                3 => '3rd Floor',
            ];

            foreach ($floorNames as $floorNum => $floorName) {
                $floor = HostelFloor::firstOrCreate(
                    ['hostel_building_id' => $ganga->id, 'floor_number' => $floorNum],
                    [
                        'name' => $floorName,
                        'gender_type' => 'boys_only',
                        'status' => 'active',
                        'display_order' => $floorNum,
                    ]
                );

                // Add rooms on this floor
                // Room 1: Non-sharing (Single)
                $r1 = HostelRoom::firstOrCreate(
                    ['hostel_building_id' => $ganga->id, 'room_number' => "G-{$floorNum}01"],
                    [
                        'hostel_floor_id' => $floor->id,
                        'room_type' => 'non_sharing',
                        'capacity' => 1,
                        'gender_allowed' => 'male_only',
                        'status' => 'available',
                        'amenities' => ['attached_washroom', 'study_table', 'wifi', 'balcony'],
                        'notes' => 'Single occupancy room with balcony.',
                    ]
                );
                HostelBed::firstOrCreate(['hostel_room_id' => $r1->id, 'bed_number' => 'Bed 1']);

                // Room 2: 2-Sharing
                $r2 = HostelRoom::firstOrCreate(
                    ['hostel_building_id' => $ganga->id, 'room_number' => "G-{$floorNum}02"],
                    [
                        'hostel_floor_id' => $floor->id,
                        'room_type' => 'sharing',
                        'capacity' => 2,
                        'gender_allowed' => 'male_only',
                        'status' => 'available',
                        'amenities' => ['study_table', 'wifi', 'attached_washroom'],
                        'notes' => 'Double sharing room with dedicated study desks.',
                    ]
                );
                HostelBed::firstOrCreate(['hostel_room_id' => $r2->id, 'bed_number' => 'Bed A']);
                HostelBed::firstOrCreate(['hostel_room_id' => $r2->id, 'bed_number' => 'Bed B']);

                // Room 3: 3-Sharing
                $r3 = HostelRoom::firstOrCreate(
                    ['hostel_building_id' => $ganga->id, 'room_number' => "G-{$floorNum}03"],
                    [
                        'hostel_floor_id' => $floor->id,
                        'room_type' => 'sharing',
                        'capacity' => 3,
                        'gender_allowed' => 'male_only',
                        'status' => 'available',
                        'amenities' => ['study_table', 'wifi'],
                        'notes' => 'Triple sharing room.',
                    ]
                );
                HostelBed::firstOrCreate(['hostel_room_id' => $r3->id, 'bed_number' => 'Bed 1']);
                HostelBed::firstOrCreate(['hostel_room_id' => $r3->id, 'bed_number' => 'Bed 2']);
                HostelBed::firstOrCreate(['hostel_room_id' => $r3->id, 'bed_number' => 'Bed 3']);
            }

            // 4. Seed Floors & Rooms for Mayurakhshi Hostel (Girls Only)
            $mFloors = [
                0 => 'Ground Floor',
                1 => '1st Floor',
                2 => '2nd Floor',
            ];

            foreach ($mFloors as $floorNum => $floorName) {
                $floor = HostelFloor::firstOrCreate(
                    ['hostel_building_id' => $mayurakhshi->id, 'floor_number' => $floorNum],
                    [
                        'name' => $floorName,
                        'gender_type' => 'girls_only',
                        'status' => 'active',
                        'display_order' => $floorNum,
                    ]
                );

                // Room 1: Single
                $r1 = HostelRoom::firstOrCreate(
                    ['hostel_building_id' => $mayurakhshi->id, 'room_number' => "M-{$floorNum}01"],
                    [
                        'hostel_floor_id' => $floor->id,
                        'room_type' => 'non_sharing',
                        'capacity' => 1,
                        'gender_allowed' => 'female_only',
                        'status' => 'available',
                        'amenities' => ['attached_washroom', 'study_table', 'wifi', 'balcony'],
                    ]
                );
                HostelBed::firstOrCreate(['hostel_room_id' => $r1->id, 'bed_number' => 'Bed 1']);

                // Room 2: 2-sharing
                $r2 = HostelRoom::firstOrCreate(
                    ['hostel_building_id' => $mayurakhshi->id, 'room_number' => "M-{$floorNum}02"],
                    [
                        'hostel_floor_id' => $floor->id,
                        'room_type' => 'sharing',
                        'capacity' => 2,
                        'gender_allowed' => 'female_only',
                        'status' => 'available',
                        'amenities' => ['study_table', 'wifi', 'attached_washroom'],
                    ]
                );
                HostelBed::firstOrCreate(['hostel_room_id' => $r2->id, 'bed_number' => 'Bed A']);
                HostelBed::firstOrCreate(['hostel_room_id' => $r2->id, 'bed_number' => 'Bed B']);

                // Room 3: 4-sharing
                $r3 = HostelRoom::firstOrCreate(
                    ['hostel_building_id' => $mayurakhshi->id, 'room_number' => "M-{$floorNum}03"],
                    [
                        'hostel_floor_id' => $floor->id,
                        'room_type' => 'sharing',
                        'capacity' => 4,
                        'gender_allowed' => 'female_only',
                        'status' => 'available',
                        'amenities' => ['study_table', 'wifi'],
                    ]
                );
                HostelBed::firstOrCreate(['hostel_room_id' => $r3->id, 'bed_number' => 'Bed 1']);
                HostelBed::firstOrCreate(['hostel_room_id' => $r3->id, 'bed_number' => 'Bed 2']);
                HostelBed::firstOrCreate(['hostel_room_id' => $r3->id, 'bed_number' => 'Bed 3']);
                HostelBed::firstOrCreate(['hostel_room_id' => $r3->id, 'bed_number' => 'Bed 4']);
            }

            // 5. Seed Teesta Hostel (Co-ed with wings)
            $tGround = HostelFloor::firstOrCreate(
                ['hostel_building_id' => $teesta->id, 'floor_number' => 0],
                [
                    'name' => 'Ground Floor (Wing A - Male)',
                    'gender_type' => 'boys_only',
                    'status' => 'active',
                    'display_order' => 0,
                ]
            );

            $t1st = HostelFloor::firstOrCreate(
                ['hostel_building_id' => $teesta->id, 'floor_number' => 1],
                [
                    'name' => '1st Floor (Wing B - Female)',
                    'gender_type' => 'girls_only',
                    'status' => 'active',
                    'display_order' => 1,
                ]
            );

            // Ground floor Male rooms
            $tr1 = HostelRoom::firstOrCreate(
                ['hostel_building_id' => $teesta->id, 'room_number' => 'T-001'],
                [
                    'hostel_floor_id' => $tGround->id,
                    'room_type' => 'non_sharing',
                    'capacity' => 1,
                    'gender_allowed' => 'male_only',
                    'status' => 'available',
                    'amenities' => ['attached_washroom', 'ac', 'study_table', 'wifi'],
                    'notes' => 'Executive Single AC room.',
                ]
            );
            HostelBed::firstOrCreate(['hostel_room_id' => $tr1->id, 'bed_number' => 'Bed 1']);

            $tr2 = HostelRoom::firstOrCreate(
                ['hostel_building_id' => $teesta->id, 'room_number' => 'T-002'],
                [
                    'hostel_floor_id' => $tGround->id,
                    'room_type' => 'sharing',
                    'capacity' => 2,
                    'gender_allowed' => 'male_only',
                    'status' => 'available',
                    'amenities' => ['study_table', 'wifi', 'attached_washroom'],
                ]
            );
            HostelBed::firstOrCreate(['hostel_room_id' => $tr2->id, 'bed_number' => 'Bed A']);
            HostelBed::firstOrCreate(['hostel_room_id' => $tr2->id, 'bed_number' => 'Bed B']);

            // 1st Floor Female rooms
            $tr3 = HostelRoom::firstOrCreate(
                ['hostel_building_id' => $teesta->id, 'room_number' => 'T-101'],
                [
                    'hostel_floor_id' => $t1st->id,
                    'room_type' => 'non_sharing',
                    'capacity' => 1,
                    'gender_allowed' => 'female_only',
                    'status' => 'available',
                    'amenities' => ['attached_washroom', 'ac', 'study_table', 'wifi'],
                ]
            );
            HostelBed::firstOrCreate(['hostel_room_id' => $tr3->id, 'bed_number' => 'Bed 1']);

            $tr4 = HostelRoom::firstOrCreate(
                ['hostel_building_id' => $teesta->id, 'room_number' => 'T-102'],
                [
                    'hostel_floor_id' => $t1st->id,
                    'room_type' => 'sharing',
                    'capacity' => 2,
                    'gender_allowed' => 'female_only',
                    'status' => 'available',
                    'amenities' => ['study_table', 'wifi', 'attached_washroom'],
                ]
            );
            HostelBed::firstOrCreate(['hostel_room_id' => $tr4->id, 'bed_number' => 'Bed A']);
            HostelBed::firstOrCreate(['hostel_room_id' => $tr4->id, 'bed_number' => 'Bed B']);

            // 6. Allocate a sample resident (if student user exists)
            $sampleStudent = User::role('Student')->first();
            $adminUser = User::role(['Super Administrator', 'Administrator'])->first();

            if ($sampleStudent) {
                // Determine appropriate room based on student's gender
                $gender = strtolower(trim((string) ($sampleStudent->gender ?? $sampleStudent->studentProfile?->gender ?? 'male')));
                $isFemale = in_array($gender, ['female', 'f', 'girl', 'woman'], true);

                $targetBed = $isFemale
                    ? HostelBed::whereHas('room', fn ($q) => $q->where('gender_allowed', 'female_only'))->where('status', 'available')->first()
                    : HostelBed::whereHas('room', fn ($q) => $q->where('gender_allowed', 'male_only'))->where('status', 'available')->first();

                if ($targetBed && ! HostelAllocation::active()->where('user_id', $sampleStudent->id)->exists()) {
                    HostelService::allocateResident([
                        'user_id' => $sampleStudent->id,
                        'hostel_bed_id' => $targetBed->id,
                        'resident_type' => 'student',
                        'start_date' => now()->subMonths(3)->toDateString(),
                        'duration_type' => 'months',
                        'duration_value' => 6,
                        'remarks' => 'Enrolled regular student accommodation.',
                        'hard_copy_submitted' => true,
                        'hard_copy_submitted_at' => now()->subMonths(3)->toDateString(),
                        'hard_copy_received_by' => $adminUser?->id,
                        'hard_copy_notes' => 'Physical form received and filed in Hostel Register Ref #042.',
                    ], $adminUser?->id);
                }
            }
        });
    }
}
