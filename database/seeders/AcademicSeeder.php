<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Section;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AcademicSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Seed Student ID Generation Config in Settings
            Setting::updateOrCreate(
                ['key' => 'student_id_config'],
                [
                    'value' => json_encode([
                        'sequence_length' => 4,
                        'default_fallback_identifier' => '0',
                        'course_identifiers' => [
                            'CC' => '1',
                            'PCC' => '3',
                            'MGP' => '4',
                            'ITP' => '5',
                            'WTS' => '6',
                        ],
                        'section_identifiers' => [
                            'Section A' => '1',
                            'Section B' => '2',
                            'Section C' => '3',
                        ],
                    ]),
                    'group' => 'academic',
                    'type' => Setting::TYPE_JSON,
                    'label' => 'Student ID Generation Config',
                    'status' => true,
                ]
            );

            // 2. Seed Batches
            $batch2026 = Batch::firstOrCreate(
                ['code' => 'BATCH-2026'],
                [
                    'name' => '2026 Batch',
                    'academic_year' => '2026',
                    'start_date' => '2026-01-01',
                    'end_date' => '2026-12-31',
                    'status' => 'active',
                    'description' => 'Academic session for UPSC Civil Services Examination 2026.',
                    'max_students' => 200,
                ]
            );

            $batch2027 = Batch::firstOrCreate(
                ['code' => 'BATCH-2027'],
                [
                    'name' => '2027 Batch',
                    'academic_year' => '2027',
                    'start_date' => '2027-01-01',
                    'end_date' => '2027-12-31',
                    'status' => 'upcoming',
                    'description' => 'Academic session for UPSC Civil Services Examination 2027.',
                    'max_students' => 250,
                ]
            );

            $batch2025 = Batch::firstOrCreate(
                ['code' => 'BATCH-2025'],
                [
                    'name' => '2025 Batch',
                    'academic_year' => '2025',
                    'start_date' => '2025-01-01',
                    'end_date' => '2025-12-31',
                    'status' => 'completed',
                    'description' => 'Academic session for UPSC Civil Services Examination 2025.',
                    'max_students' => 180,
                ]
            );

            // 3. Seed Courses
            $coursesData = [
                [
                    'name' => 'Composite Course (CC)',
                    'code' => 'CC',
                    'category' => 'Classroom',
                    'description' => 'Flagship 10-month classroom coaching programme covering UPSC CSE Prelims, Mains, and general mentorship with optional subject guidance.',
                    'duration' => '10 Months',
                    'fee' => 1000.00, // Monthly fee for UR / General students
                    'fee_type' => 'monthly',
                    'fee_reserved' => 500.00, // Monthly fee for SC, ST, OBC, EWS students
                    'capacity' => 200, // Max intake 200 students
                    'has_sections' => true,
                    'identifier_code' => '1',
                    'admit_once_only' => true,
                    'status' => 'active',
                ],
                [
                    'name' => 'Prelims Crash Course (PCC)',
                    'code' => 'PCC',
                    'category' => 'Crash Course',
                    'description' => 'Intensive 3-month (12-week) high-yield revision and test series module dedicated to UPSC Prelims General Studies (Paper 1) and CSAT (Paper 2).',
                    'duration' => '3 Months',
                    'fee' => 1500.00, // One-time fee for UR / General students
                    'fee_type' => 'one_time',
                    'fee_reserved' => 750.00, // One-time fee for SC, ST, OBC, EWS students
                    'capacity' => null, // Unlimited intake
                    'has_sections' => false,
                    'identifier_code' => '3',
                    'admit_once_only' => false,
                    'status' => 'active',
                ],
                [
                    'name' => 'Mains Guidance Programme (MGP)',
                    'code' => 'MGP',
                    'category' => 'Guidance',
                    'description' => 'Systematic answer writing evaluation, GS Papers 1 to 4 coverage, and essay writing mentorship for UPSC Mains.',
                    'duration' => '16 Weeks',
                    'fee' => 12000.00,
                    'fee_type' => 'one_time',
                    'fee_reserved' => 6000.00,
                    'capacity' => null,
                    'has_sections' => false,
                    'identifier_code' => '4',
                    'admit_once_only' => false,
                    'status' => 'active',
                ],
                [
                    'name' => 'Interview Training and Mock Interview Programme (ITP)',
                    'code' => 'ITP',
                    'category' => 'Interview',
                    'description' => 'Comprehensive personality test grooming, DAF in-depth scrutiny, and mock interview panels with retired diplomats and civil servants.',
                    'duration' => '4 Weeks',
                    'fee' => 10000.00,
                    'fee_type' => 'one_time',
                    'fee_reserved' => 5000.00,
                    'capacity' => null,
                    'has_sections' => false,
                    'identifier_code' => '5',
                    'admit_once_only' => false,
                    'status' => 'active',
                ],
                [
                    'name' => 'Weekend Test Series (WTS)',
                    'code' => 'WTS',
                    'category' => 'Test Series',
                    'description' => 'Rigorous weekend simulated test series with all-India ranking and video discussion analysis.',
                    'duration' => '24 Weeks',
                    'fee' => 3000.00,
                    'fee_type' => 'one_time',
                    'fee_reserved' => 1500.00,
                    'capacity' => null,
                    'has_sections' => false,
                    'identifier_code' => '6',
                    'admit_once_only' => false,
                    'status' => 'active',
                ],
                [
                    'name' => 'Free UPSC CSE Foundation Seminar (FOS)',
                    'code' => 'FOS',
                    'category' => 'Guidance',
                    'description' => 'Free introductory orientation seminar and workshop for all UPSC civil services aspirants across West Bengal.',
                    'duration' => '2 Weeks',
                    'fee' => 0.00,
                    'fee_type' => 'free',
                    'fee_reserved' => 0.00,
                    'capacity' => null,
                    'has_sections' => false,
                    'identifier_code' => '7',
                    'admit_once_only' => false,
                    'status' => 'active',
                ],
            ];

            $seededCourses = [];
            foreach ($coursesData as $courseItem) {
                $seededCourses[$courseItem['code']] = Course::updateOrCreate(
                    ['code' => $courseItem['code']],
                    $courseItem
                );
            }

            // 4. Attach Courses to 2026 Batch & 2027 Batch
            foreach ($seededCourses as $code => $course) {
                $capacity = $code === 'CC' ? 200 : null; // CC = 200, PCC & others = unlimited (null)
                $batch2026->courses()->syncWithoutDetaching([
                    $course->id => [
                        'capacity' => $capacity,
                        'fee' => $course->fee,
                        'status' => 'active',
                    ],
                ]);

                $batch2027->courses()->syncWithoutDetaching([
                    $course->id => [
                        'capacity' => $capacity,
                        'fee' => $course->fee,
                        'status' => 'active',
                    ],
                ]);
            }

            // 5. Seed Sections for Composite Course under 2026 Batch
            $ccCourse = $seededCourses['CC'];
            Section::updateOrCreate(
                [
                    'batch_id' => $batch2026->id,
                    'course_id' => $ccCourse->id,
                    'code' => '1',
                ],
                [
                    'name' => 'Section A',
                    'capacity' => 100,
                    'room_number' => 'Hall 1',
                    'timing' => 'Mon–Fri · 08:00–11:00',
                    'status' => 'active',
                ]
            );

            Section::updateOrCreate(
                [
                    'batch_id' => $batch2026->id,
                    'course_id' => $ccCourse->id,
                    'code' => '2',
                ],
                [
                    'name' => 'Section B',
                    'capacity' => 100,
                    'room_number' => 'Hall 2',
                    'timing' => 'Mon–Fri · 16:00–19:00',
                    'status' => 'active',
                ]
            );

            // 6. Seed Sections for Composite Course under 2027 Batch
            Section::updateOrCreate(
                [
                    'batch_id' => $batch2027->id,
                    'course_id' => $ccCourse->id,
                    'code' => '1',
                ],
                [
                    'name' => 'Section A',
                    'capacity' => 100,
                    'room_number' => 'Hall 1',
                    'timing' => 'Mon–Fri · 08:00–11:00',
                    'status' => 'active',
                ]
            );

            Section::updateOrCreate(
                [
                    'batch_id' => $batch2027->id,
                    'course_id' => $ccCourse->id,
                    'code' => '2',
                ],
                [
                    'name' => 'Section B',
                    'capacity' => 100,
                    'room_number' => 'Hall 2',
                    'timing' => 'Mon–Fri · 16:00–19:00',
                    'status' => 'active',
                ]
            );
        });
    }
}
