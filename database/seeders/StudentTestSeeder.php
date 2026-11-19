<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\StudentTest;
use App\Models\StudentTestResult;
use App\Models\StudentTestSubject;
use App\Models\User;
use App\Services\Test\TestScoreCalculatorService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentTestSeeder extends Seeder
{
    public function run(): void
    {
        $calculator = app(TestScoreCalculatorService::class);

        $batch = Batch::first();
        $course = Course::first();

        if (! $batch || ! $course) {
            return;
        }

        $sectionA = Section::firstOrCreate(
            ['course_id' => $course->id, 'name' => 'Section A'],
            ['batch_id' => $batch->id, 'code' => 'SEC-A', 'capacity' => 100, 'status' => 'active']
        );

        $sectionB = Section::firstOrCreate(
            ['course_id' => $course->id, 'name' => 'Section B'],
            ['batch_id' => $batch->id, 'code' => 'SEC-B', 'capacity' => 100, 'status' => 'active']
        );

        // Ensure 15 enrolled students for realistic ranking
        $studentNames = [
            ['Subhankar Basak', 'subhankarbasakmail@gmail.com', '202600001', $sectionA->id],
            ['Ananya Mukherjee', 'ananya.m@example.com', '202600002', $sectionA->id],
            ['Debjit Chakraborty', 'debjit.c@example.com', '202600003', $sectionA->id],
            ['Priyanka Banerjee', 'priyanka.b@example.com', '202600004', $sectionA->id],
            ['Rohit Sen', 'rohit.sen@example.com', '202600005', $sectionB->id],
            ['Sourav Ghosh', 'sourav.g@example.com', '202600006', $sectionB->id],
            ['Rupa Roy', 'rupa.roy@example.com', '202600007', $sectionA->id],
            ['Arnab Bhattacharya', 'arnab.b@example.com', '202600008', $sectionB->id],
            ['Moumita Das', 'moumita.d@example.com', '202600009', $sectionA->id],
            ['Siddhartha Dutta', 'siddhartha.d@example.com', '202600010', $sectionB->id],
            ['Pooja Shaw', 'pooja.shaw@example.com', '202600011', $sectionA->id],
            ['Koushik Ganguly', 'koushik.g@example.com', '202600012', $sectionB->id],
            ['Tanushree Pal', 'tanushree.p@example.com', '202600013', $sectionA->id],
            ['Abhijit Kundu', 'abhijit.k@example.com', '202600014', $sectionB->id],
            ['Sneha Karmakar', 'sneha.k@example.com', '202600015', $sectionB->id],
        ];

        $students = [];
        foreach ($studentNames as $idx => [$name, $email, $studentId, $secId]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'student_id' => $studentId,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );

            // Assign Student Role
            if (! $user->hasRole('Student')) {
                $user->assignRole('Student');
            }

            // Create Enrollment
            $enrollment = Enrollment::firstOrCreate(
                ['user_id' => $user->id, 'course_id' => $course->id],
                [
                    'enrollment_no' => 'EN-'.($batch->academic_year ?? '2026').'-'.($course->code ?? 'CC').'-'.str_pad((string) ($idx + 1), 4, '0', STR_PAD_LEFT),
                    'student_id' => $studentId,
                    'batch_id' => $batch->id,
                    'section_id' => $secId,
                    'roll_no' => $studentId,
                    'status' => 'active',
                    'enrolled_at' => now()->subMonths(2),
                    'fee_status' => 'paid',
                ]
            );

            $students[] = [
                'user' => $user,
                'enrollment' => $enrollment,
                'section_id' => $secId,
            ];
        }

        // Test 1: Full Length Prelims Mock #1 (GS + CSAT)
        $test1 = StudentTest::firstOrCreate(
            ['code' => 'TEST-2026-GS-01'],
            [
                'uuid' => (string) Str::uuid(),
                'title' => 'UPSC Prelims Mock Test #1 - GS Paper 1 & CSAT',
                'batch_id' => $batch->id,
                'course_id' => $course->id,
                'section_id' => null, // All sections
                'test_stage' => StudentTest::STAGE_PRELIMS,
                'test_type' => StudentTest::TYPE_MCQ,
                'test_for' => StudentTest::FOR_FULL_LENGTH,
                'test_number' => 1,
                'total_marks' => 200.00,
                'pass_marks' => 66.00,
                'total_questions' => 100,
                'mode' => 'offline',
                'test_date' => Carbon::parse('2026-10-18'),
                'test_day' => 'Sunday',
                'start_time' => '10:00 AM',
                'end_time' => '12:00 PM',
                'duration_minutes' => 120,
                'venue_room' => 'Salt Lake Main Examination Hall A & B',
                'status' => StudentTest::STATUS_COMPLETED,
                'negative_marking_ratio' => 0.33,
                'marks_per_question' => 2.00,
                'instructions' => 'Negative marking of 1/3rd (0.66 marks) per incorrect response. Use black ball point pen only.',
                'solutions_published_at' => Carbon::parse('2026-10-18 13:00:00'),
            ]
        );

        StudentTestSubject::firstOrCreate(
            ['student_test_id' => $test1->id, 'subject_name' => 'General Studies (Paper 1)'],
            [
                'subject_code' => 'GS-1',
                'questions_count' => 50,
                'max_marks' => 100.00,
                'marks_per_question' => 2.00,
                'negative_marking' => 0.66,
                'display_order' => 1,
            ]
        );

        StudentTestSubject::firstOrCreate(
            ['student_test_id' => $test1->id, 'subject_name' => 'CSAT Aptitude (Paper 2)'],
            [
                'subject_code' => 'CSAT',
                'questions_count' => 50,
                'max_marks' => 100.00,
                'marks_per_question' => 2.00,
                'negative_marking' => 0.66,
                'display_order' => 2,
            ]
        );

        // Seed realistic results for Test 1
        $scorePresets = [
            [82, 12, 'Outstanding performance in CSAT and Polity; maintain consistency.'], // 82 * 2 - 12 * 0.66 = 156.08
            [78, 14, 'Very good accuracy across GS Paper 1. Focus on modern history.'],
            [75, 15, 'Consistent performance. Well placed in top percentile.'],
            [72, 18, 'Good analytical reasoning, work on negative reduction.'],
            [68, 20, 'Solid attempt rate, review economic survey questions.'],
            [65, 22, 'Satisfactory. Revision of geography fundamentals required.'],
            [62, 25, 'Above qualifying cutoff. Improve CSAT comprehension speed.'],
            [58, 28, 'Decent effort. Need to avoid wild guesses.'],
            [55, 30, 'Qualifying baseline met. Work on time management.'],
            [52, 32, 'Borderline performance. Regular mock revision advised.'],
            [48, 35, 'Below expected accuracy. Comprehensive revision needed.'],
            [44, 38, 'High negative penalty. Focus on high-confidence questions.'],
            [40, 42, 'Needs significant improvement. Schedule mentor session.'],
        ];

        foreach ($students as $idx => $sData) {
            $user = $sData['user'];
            $secId = $sData['section_id'];

            if ($idx === 14) {
                // One absent student
                StudentTestResult::updateOrCreate(
                    ['student_test_id' => $test1->id, 'user_id' => $user->id],
                    [
                        'enrollment_id' => $sData['enrollment']->id,
                        'section_id' => $secId,
                        'roll_number' => $user->student_id,
                        'attendance_status' => StudentTestResult::STATUS_ABSENT,
                        'total_questions' => 100,
                        'total_marks' => 200.00,
                        'marks_obtained' => 0.00,
                        'percentage' => 0.00,
                        'remarks' => 'Absent with prior leave notice.',
                    ]
                );
            } else {
                $preset = $scorePresets[$idx % count($scorePresets)];
                $right = $preset[0];
                $wrong = $preset[1];
                $blank = 100 - ($right + $wrong);

                $res = StudentTestResult::updateOrCreate(
                    ['student_test_id' => $test1->id, 'user_id' => $user->id],
                    [
                        'enrollment_id' => $sData['enrollment']->id,
                        'section_id' => $secId,
                        'roll_number' => $user->student_id,
                        'attendance_status' => StudentTestResult::STATUS_PRESENT,
                        'total_questions' => 100,
                        'total_marks' => 200.00,
                        'right_answers' => $right,
                        'wrong_answers' => $wrong,
                        'blank_answers' => $blank,
                        'remarks' => $preset[2],
                        'feedback' => 'Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',
                    ]
                );
            }
        }

        $calculator->recalculateAndRank($test1);

        // Test 2: Sectional Mock Test #2 (Modern Indian History)
        $test2 = StudentTest::firstOrCreate(
            ['code' => 'TEST-2026-HIST-02'],
            [
                'uuid' => (string) Str::uuid(),
                'title' => 'UPSC Sectional Test #2 - Modern Indian History & Freedom Struggle',
                'batch_id' => $batch->id,
                'course_id' => $course->id,
                'section_id' => null,
                'test_stage' => StudentTest::STAGE_PRELIMS,
                'test_type' => StudentTest::TYPE_MCQ,
                'test_for' => StudentTest::FOR_SECTIONAL,
                'test_number' => 2,
                'total_marks' => 100.00,
                'pass_marks' => 40.00,
                'total_questions' => 50,
                'mode' => 'offline',
                'test_date' => Carbon::parse('2026-10-25'),
                'test_day' => 'Sunday',
                'start_time' => '10:00 AM',
                'end_time' => '11:00 AM',
                'duration_minutes' => 60,
                'venue_room' => 'Hall 2 (Salt Lake)',
                'status' => StudentTest::STATUS_COMPLETED,
                'negative_marking_ratio' => 0.33,
                'marks_per_question' => 2.00,
                'instructions' => 'Focus on 1857 revolt, Congress sessions, Gandhian era, and constitutional developments.',
                'solutions_published_at' => Carbon::parse('2026-10-25 12:00:00'),
            ]
        );

        StudentTestSubject::firstOrCreate(
            ['student_test_id' => $test2->id, 'subject_name' => 'Modern Indian History'],
            [
                'subject_code' => 'HIST',
                'questions_count' => 50,
                'max_marks' => 100.00,
                'marks_per_question' => 2.00,
                'negative_marking' => 0.66,
                'display_order' => 1,
            ]
        );

        foreach ($students as $idx => $sData) {
            $user = $sData['user'];
            $secId = $sData['section_id'];

            $right = rand(26, 38);
            $wrong = rand(3, min(10, 50 - $right));
            $blank = max(0, 50 - ($right + $wrong));

            StudentTestResult::updateOrCreate(
                ['student_test_id' => $test2->id, 'user_id' => $user->id],
                [
                    'enrollment_id' => $sData['enrollment']->id,
                    'section_id' => $secId,
                    'roll_number' => $user->student_id,
                    'attendance_status' => StudentTestResult::STATUS_PRESENT,
                    'total_questions' => 50,
                    'total_marks' => 100.00,
                    'right_answers' => $right,
                    'wrong_answers' => $wrong,
                    'blank_answers' => $blank,
                    'remarks' => 'Good historical chronological recall.',
                ]
            );
        }

        $calculator->recalculateAndRank($test2);

        // Test 3: UPSC Mains Descriptive Test #1 (General Studies Paper 2)
        $test3 = StudentTest::firstOrCreate(
            ['code' => 'TEST-2026-MAINS-01'],
            [
                'uuid' => (string) Str::uuid(),
                'title' => 'UPSC Mains Answer Writing #1 - GS Paper 2 (Governance & Constitution)',
                'batch_id' => $batch->id,
                'course_id' => $course->id,
                'section_id' => $sectionA->id, // Specific section test!
                'test_stage' => StudentTest::STAGE_MAINS,
                'test_type' => StudentTest::TYPE_ANSWER_WRITING,
                'test_for' => StudentTest::FOR_CLASS_TEST,
                'test_number' => 1,
                'total_marks' => 250.00,
                'pass_marks' => 100.00,
                'total_questions' => 20,
                'mode' => 'offline',
                'test_date' => Carbon::parse('2026-10-27'),
                'test_day' => 'Tuesday',
                'start_time' => '02:00 PM',
                'end_time' => '05:00 PM',
                'duration_minutes' => 180,
                'venue_room' => 'Classroom 301, Section A Wing',
                'status' => StudentTest::STATUS_COMPLETED,
                'instructions' => 'Answer all 20 questions in prescribed word limits (150 words for 10M, 250 words for 15M).',
                'solutions_published_at' => Carbon::parse('2026-10-27 18:00:00'),
            ]
        );

        foreach ($students as $idx => $sData) {
            if ($sData['section_id'] !== $sectionA->id) {
                continue;
            }
            $user = $sData['user'];
            $marks = rand(95, 148);

            StudentTestResult::updateOrCreate(
                ['student_test_id' => $test3->id, 'user_id' => $user->id],
                [
                    'enrollment_id' => $sData['enrollment']->id,
                    'section_id' => $sectionA->id,
                    'roll_number' => $user->student_id,
                    'attendance_status' => StudentTestResult::STATUS_PRESENT,
                    'total_questions' => 20,
                    'total_marks' => 250.00,
                    'marks_obtained' => $marks,
                    'percentage' => round(($marks / 250) * 100, 2),
                    'remarks' => 'Good answer structuring with constitutional provisions and Supreme Court judgements.',
                    'feedback' => 'Work on introduction brevity and conclude with forward-looking way forward.',
                ]
            );
        }

        $calculator->recalculateAndRank($test3);

        // Test 4: Upcoming Mock Test #3 (Scheduled for next Sunday)
        StudentTest::firstOrCreate(
            ['code' => 'TEST-2026-GS-03'],
            [
                'uuid' => (string) Str::uuid(),
                'title' => 'UPSC Prelims Full Mock #3 - Indian Economy, Budget & Environment',
                'batch_id' => $batch->id,
                'course_id' => $course->id,
                'section_id' => null,
                'test_stage' => StudentTest::STAGE_PRELIMS,
                'test_type' => StudentTest::TYPE_MCQ,
                'test_for' => StudentTest::FOR_FULL_LENGTH,
                'test_number' => 3,
                'total_marks' => 200.00,
                'pass_marks' => 66.00,
                'total_questions' => 100,
                'mode' => 'offline',
                'test_date' => Carbon::parse('2026-11-01'),
                'test_day' => 'Sunday',
                'start_time' => '10:00 AM',
                'end_time' => '12:00 PM',
                'duration_minutes' => 120,
                'venue_room' => 'Main Campus Halls 1, 2 & 3',
                'status' => StudentTest::STATUS_SCHEDULED,
                'negative_marking_ratio' => 0.3333,
                'marks_per_question' => 2.00,
                'notify_students' => true,
                'notification_channels' => ['in_app', 'email', 'whatsapp'],
                'instructions' => 'Bring admit card and black ballpoint pen. Reporting time 09:30 AM.',
            ]
        );

        // Test 5: Dedicated UPSC CSAT / GS Paper II (Qualifying Paper)
        $testCsat = StudentTest::firstOrCreate(
            ['code' => 'TEST-2026-CSAT-01'],
            [
                'uuid' => (string) Str::uuid(),
                'title' => 'UPSC Prelims CSAT Paper II Full Mock Test',
                'batch_id' => $batch->id,
                'course_id' => $course->id,
                'section_id' => null,
                'test_stage' => StudentTest::STAGE_PRELIMS,
                'test_type' => StudentTest::TYPE_MCQ,
                'test_for' => StudentTest::FOR_FULL_LENGTH,
                'test_number' => 4,
                'total_marks' => 200.00,
                'pass_marks' => 66.67, // Minimum 33.33% qualifying benchmark
                'total_questions' => 80,
                'mode' => 'offline',
                'test_date' => Carbon::parse('2026-10-26'),
                'test_day' => 'Monday',
                'start_time' => '02:30 PM',
                'end_time' => '04:30 PM',
                'duration_minutes' => 120,
                'venue_room' => 'Salt Lake Main Examination Hall A',
                'status' => StudentTest::STATUS_COMPLETED,
                'negative_marking_ratio' => 0.3333,
                'marks_per_question' => 2.50,
                'instructions' => "1. CSAT / General Studies Paper II is Qualifying Only (Requires ≥ 33% / 66.67 Marks).\n2. Total Questions: 80 | Maximum Marks: 200.\n3. Each correct answer carries +2.50 Marks.\n4. Penalty for wrong answer is 1/3rd (-0.83 Marks).\n5. Unattempted questions carry zero penalty.",
                'solutions_published_at' => Carbon::parse('2026-10-26 17:00:00'),
            ]
        );

        StudentTestSubject::firstOrCreate(
            ['student_test_id' => $testCsat->id, 'subject_name' => 'CSAT / General Studies Paper II (Qualifying)'],
            [
                'subject_code' => 'CSAT-2',
                'questions_count' => 80,
                'max_marks' => 200.00,
                'marks_per_question' => 2.50,
                'negative_marking' => 0.83,
                'display_order' => 1,
            ]
        );

        $csatScores = [
            [62, 8, 'Excellent comprehension and reasoning speed; comfortably qualified.'],
            [58, 10, 'Strong quantitative aptitude. Well clear of qualifying mark.'],
            [54, 12, 'Good reading comprehension accuracy; safe qualifying margin.'],
            [50, 15, 'Solid performance in logic and data interpretation.'],
            [46, 16, 'Above qualifying cutoff. Maintain focus on reading speed.'],
            [42, 18, 'Safely qualified (≥33%). Good accuracy balance.'],
            [38, 20, 'Qualified. Focus on eliminating borderline guesses.'],
            [34, 22, 'Barely qualified above 66.67 mark cutoff. Remedial practice recommended.'],
            [30, 24, 'Below 33% qualifying benchmark. Intensive CSAT practice required.'],
            [26, 28, 'Failed to meet 33% qualifying cutoff. High negative penalty.'],
        ];

        foreach ($students as $idx => $sData) {
            if ($idx >= count($csatScores)) {
                break;
            }
            $user = $sData['user'];
            $secId = $sData['section_id'];
            $cs = $csatScores[$idx];
            $right = $cs[0];
            $wrong = $cs[1];
            $blank = 80 - ($right + $wrong);

            $res = StudentTestResult::updateOrCreate(
                ['student_test_id' => $testCsat->id, 'user_id' => $user->id],
                [
                    'enrollment_id' => $sData['enrollment']->id,
                    'section_id' => $secId,
                    'roll_number' => $user->student_id,
                    'attendance_status' => StudentTestResult::STATUS_PRESENT,
                    'total_questions' => 80,
                    'total_marks' => 200.00,
                    'right_answers' => $right,
                    'wrong_answers' => $wrong,
                    'blank_answers' => $blank,
                    'remarks' => $cs[2],
                    'feedback' => 'UPSC CSAT requires minimum 33% (66.67 Marks). Practice previous years questions regularly.',
                ]
            );
            $res->computeScores($testCsat);
            $res->save();
        }

        $calculator->recalculateAndRank($testCsat);
    }
}
