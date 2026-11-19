<?php

namespace Database\Seeders;

use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExaminationCenter;
use App\Models\ExaminationCenterRoom;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ExaminationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::first();

        // 1. Examination Centers
        $center1 = ExaminationCenter::firstOrCreate(
            ['code' => 'KOL-SL-01'],
            [
                'name' => 'SNTCSSC Salt Lake Main Campus',
                'address' => 'Block FC, Sector III, Salt Lake City',
                'city' => 'Kolkata',
                'district' => 'North 24 Parganas',
                'state' => 'West Bengal',
                'pincode' => '700106',
                'contact_person' => 'Prof. Debabrata Sen',
                'contact_phone' => '+91 33 2337 4000',
                'contact_email' => 'saltlake.exams@sntcssc.in',
                'total_capacity' => 120,
                'is_active' => true,
            ]
        );

        $center2 = ExaminationCenter::firstOrCreate(
            ['code' => 'SIL-RC-02'],
            [
                'name' => 'North Bengal University Regional Examination Hall',
                'address' => 'Raja Rammohunpur, Siliguri',
                'city' => 'Siliguri',
                'district' => 'Darjeeling',
                'state' => 'West Bengal',
                'pincode' => '734013',
                'contact_person' => 'Dr. Subhash Roy',
                'contact_phone' => '+91 353 2776 331',
                'contact_email' => 'siliguri.exams@sntcssc.in',
                'total_capacity' => 80,
                'is_active' => true,
            ]
        );

        $center3 = ExaminationCenter::firstOrCreate(
            ['code' => 'DGP-CT-03'],
            [
                'name' => 'Durgapur Administrative Examination Center',
                'address' => 'City Centre Campus, Mahatma Gandhi Road',
                'city' => 'Durgapur',
                'district' => 'Paschim Bardhaman',
                'state' => 'West Bengal',
                'pincode' => '713216',
                'contact_person' => 'Shri Anirban Mukherjee',
                'contact_phone' => '+91 343 2545 100',
                'contact_email' => 'durgapur.exams@sntcssc.in',
                'total_capacity' => 60,
                'is_active' => true,
            ]
        );

        // 2. Center Rooms
        $room1A = ExaminationCenterRoom::firstOrCreate(
            ['examination_center_id' => $center1->id, 'room_number' => 'Hall-A'],
            ['floor' => 'Ground Floor', 'building_name' => 'Vidya Bhavan', 'capacity' => 60, 'is_active' => true]
        );
        $room1B = ExaminationCenterRoom::firstOrCreate(
            ['examination_center_id' => $center1->id, 'room_number' => 'Hall-B'],
            ['floor' => '1st Floor', 'building_name' => 'Vidya Bhavan', 'capacity' => 60, 'is_active' => true]
        );

        $room2A = ExaminationCenterRoom::firstOrCreate(
            ['examination_center_id' => $center2->id, 'room_number' => 'Seminar-1'],
            ['floor' => 'Ground Floor', 'building_name' => 'Administrative Wing', 'capacity' => 40, 'is_active' => true]
        );
        $room2B = ExaminationCenterRoom::firstOrCreate(
            ['examination_center_id' => $center2->id, 'room_number' => 'Seminar-2'],
            ['floor' => '1st Floor', 'building_name' => 'Administrative Wing', 'capacity' => 40, 'is_active' => true]
        );

        $room3A = ExaminationCenterRoom::firstOrCreate(
            ['examination_center_id' => $center3->id, 'room_number' => 'Auditorium-1'],
            ['floor' => 'Ground Floor', 'building_name' => 'Main Center', 'capacity' => 60, 'is_active' => true]
        );

        // 3. Exams
        $exam1 = Exam::firstOrCreate(
            ['exam_code' => 'CSCEE-2027'],
            [
                'uuid' => (string) Str::uuid(),
                'exam_name' => 'Civil Services Coaching Entrance Examination 2027',
                'academic_year' => '2026-2027',
                'conducting_body' => 'Satyendranath Tagore Civil Services Study Centre',
                'notification_date' => now()->subDays(15)->toDateString(),
                'apply_start' => now()->subDays(10)->toDateString(),
                'apply_end' => now()->addDays(20)->toDateString(),
                'exam_date' => now()->addDays(45)->toDateString(),
                'exam_time' => '11:00 AM - 01:00 PM',
                'reporting_time' => '10:00 AM',
                'duration_minutes' => 120,
                'fee_general' => 100.00,
                'fee_reserved' => 50.00,
                'total_vacancies' => 200,
                'status' => Exam::STATUS_PUBLISHED,
                'instructions' => "1. Two hours objective paper consisting of General Studies (Paper I) and CSAT (Paper II).\n2. Negative marking of 0.33 marks for each incorrect response.",
                'admit_card_instructions' => "1. Admit card printout is mandatory along with Government Photo ID (Aadhaar/EPIC/Passport).\n2. Reach the examination center at least 60 minutes before test commencement.\n3. Digital watches, smartphones, and bags are prohibited inside test rooms.",
                'admit_card_release_date' => now()->addDays(30)->toDateString(),
                'created_by' => $admin?->id,
            ]
        );

        $exam2 = Exam::firstOrCreate(
            ['exam_code' => 'WBCS-MOCK-26'],
            [
                'uuid' => (string) Str::uuid(),
                'exam_name' => 'WBCS Foundation Prelims Full Mock Test Series 2026',
                'academic_year' => '2026-2027',
                'conducting_body' => 'Academic Evaluation Board, SNTCSSC',
                'notification_date' => now()->subDays(5)->toDateString(),
                'apply_start' => now()->subDays(3)->toDateString(),
                'apply_end' => now()->addDays(10)->toDateString(),
                'exam_date' => now()->addDays(14)->toDateString(),
                'exam_time' => '10:30 AM - 01:00 PM',
                'reporting_time' => '09:30 AM',
                'duration_minutes' => 150,
                'fee_general' => 0.00,
                'fee_reserved' => 0.00,
                'total_vacancies' => 150,
                'status' => Exam::STATUS_PUBLISHED,
                'instructions' => 'OMR based 200 marks simulated paper covering English, Science, History, Geography, Polity & Reasoning.',
                'admit_card_instructions' => 'Bring Black Ballpoint Pen and institutional ID card.',
                'admit_card_release_date' => now()->addDays(7)->toDateString(),
                'created_by' => $admin?->id,
            ]
        );

        // Mappings
        $exam1->centers()->syncWithoutDetaching([
            $center1->id => ['allocated_seats' => 120, 'is_active' => true],
            $center2->id => ['allocated_seats' => 80, 'is_active' => true],
            $center3->id => ['allocated_seats' => 60, 'is_active' => true],
        ]);

        $exam2->centers()->syncWithoutDetaching([
            $center1->id => ['allocated_seats' => 120, 'is_active' => true],
        ]);

        // 4. Sample Candidates
        $candidates = [
            [
                'candidate_name' => 'Arunava Dasgupta',
                'candidate_email' => 'arunava.dasgupta@example.com',
                'candidate_phone' => '+91 98301 22334',
                'roll_number' => 'CSCEE-27-KOL-0001',
                'examination_center_id' => $center1->id,
                'examination_center_room_id' => $room1A->id,
                'seat_number' => 'Hall-A-S001',
                'attendance_status' => ExamCandidate::ATTENDANCE_PRESENT,
                'marks_obtained' => 142.50,
                'rank' => 1,
                'is_qualified' => true,
            ],
            [
                'candidate_name' => 'Priyanka Banerjee',
                'candidate_email' => 'priyanka.b@example.com',
                'candidate_phone' => '+91 98310 99887',
                'roll_number' => 'CSCEE-27-KOL-0002',
                'examination_center_id' => $center1->id,
                'examination_center_room_id' => $room1A->id,
                'seat_number' => 'Hall-A-S002',
                'attendance_status' => ExamCandidate::ATTENDANCE_PRESENT,
                'marks_obtained' => 138.00,
                'rank' => 2,
                'is_qualified' => true,
            ],
            [
                'candidate_name' => 'Sourav Mukherjee',
                'candidate_email' => 'sourav.m@example.com',
                'candidate_phone' => '+91 94330 11223',
                'roll_number' => 'CSCEE-27-SIL-0001',
                'examination_center_id' => $center2->id,
                'examination_center_room_id' => $room2A->id,
                'seat_number' => 'Seminar-1-S001',
                'attendance_status' => ExamCandidate::ATTENDANCE_PENDING,
            ],
        ];

        foreach ($candidates as $cData) {
            ExamCandidate::firstOrCreate(
                [
                    'exam_id' => $exam1->id,
                    'roll_number' => $cData['roll_number'],
                ],
                array_merge($cData, [
                    'admit_card_token' => Str::random(32),
                ])
            );
        }
    }
}
