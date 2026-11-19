<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Setting;
use App\Models\StudentTest;
use App\Models\StudentTestResult;
use App\Models\Team;
use App\Models\User;
use App\Services\RbacService;
use App\Services\Test\StudentTestService;
use App\Services\Test\TestImportExportService;
use App\Services\Test\TestReportService;
use App\Services\Test\TestScoreCalculatorService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    RbacService::seedDefaults();

    // Create Admin User
    $this->admin = User::factory()->create([
        'name' => 'Admin Controller',
        'email' => 'admin.test@sntcssc.in',
        'email_verified_at' => now(),
    ]);
    $this->admin->assignRole('Super Administrator');

    // Create Team
    $this->team = Team::create([
        'name' => 'Salt Lake Main Campus',
        'slug' => 'sntcssc-main',
        'user_id' => $this->admin->id,
        'personal_team' => true,
    ]);
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->admin->update(['current_team_id' => $this->team->id]);

    // Create Academic Batch & Course
    $this->batch = Batch::create([
        'name' => 'UPSC 2027 Foundation Batch',
        'code' => 'BATCH-2027-UPSC',
        'academic_year' => '2027',
        'start_date' => '2027-01-01',
        'end_date' => '2027-12-31',
        'status' => 'active',
    ]);

    $this->course = Course::create([
        'name' => 'General Studies Integrated Prelims & Mains',
        'code' => 'GS-INTEG',
        'category' => 'Classroom',
        'fee' => 1200.00,
        'has_sections' => true,
        'status' => 'active',
    ]);

    $this->sectionA = Section::create([
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'name' => 'Section Alpha',
        'code' => 'SEC-A',
        'capacity' => 60,
        'status' => 'active',
    ]);

    // Create Student Users & Enrollments
    $this->student1 = User::factory()->create([
        'name' => 'Aarav Sharma',
        'email' => 'aarav@example.com',
        'student_id' => 'STU-2027-001',
        'email_verified_at' => now(),
    ]);
    $this->student1->assignRole('Student');
    $this->student1->teams()->attach($this->team, ['role' => 'member']);
    $this->student1->update(['current_team_id' => $this->team->id]);

    $this->enrollment1 = Enrollment::create([
        'user_id' => $this->student1->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'section_id' => $this->sectionA->id,
        'roll_no' => 'ROLL-001',
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    $this->student2 = User::factory()->create([
        'name' => 'Ishita Sen',
        'email' => 'ishita@example.com',
        'student_id' => 'STU-2027-002',
        'email_verified_at' => now(),
    ]);
    $this->student2->assignRole('Student');
    $this->student2->teams()->attach($this->team, ['role' => 'member']);
    $this->student2->update(['current_team_id' => $this->team->id]);

    $this->enrollment2 = Enrollment::create([
        'user_id' => $this->student2->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'section_id' => $this->sectionA->id,
        'roll_no' => 'ROLL-002',
        'status' => 'active',
        'enrolled_at' => now(),
    ]);
});

test('admin can access examinations listing page', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.tests.index', ['current_team' => $this->team->slug]))
        ->assertStatus(200);
});

test('unauthorized user cannot access examinations management', function () {
    $guest = User::factory()->create(['email_verified_at' => now()]);
    $guestTeam = Team::create([
        'name' => 'Guest Team',
        'slug' => 'guest-team',
        'user_id' => $guest->id,
        'personal_team' => true,
    ]);
    $guest->teams()->attach($guestTeam, ['role' => 'member']);
    $guest->update(['current_team_id' => $guestTeam->id]);

    $this->actingAs($guest)
        ->get(route('admin.tests.index', ['current_team' => $guestTeam->slug]))
        ->assertStatus(403);
});

test('test service schedules examination with composite subjects and initializes candidate roster', function () {
    $service = app(StudentTestService::class);

    $testData = [
        'title' => 'Mock Test #1 - GS & CSAT',
        'code' => 'TEST-2027-0001',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $this->sectionA->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'test_number' => 1,
        'total_marks' => 200.00,
        'pass_marks' => 66.00,
        'total_questions' => 100,
        'mode' => 'offline',
        'test_date' => now()->addDays(5)->format('Y-m-d'),
        'start_time' => '10:00 AM',
        'end_time' => '12:00 PM',
        'duration_minutes' => 120,
        'venue_room' => 'Hall Alpha',
        'negative_marking_ratio' => 0.33,
        'marks_per_question' => 2.00,
    ];

    $subjects = [
        [
            'subject_name' => 'General Studies Paper 1',
            'subject_code' => 'GS-1',
            'questions_count' => 50,
            'marks_per_question' => 2.00,
            'negative_marking' => 0.66,
            'max_marks' => 100.00,
        ],
        [
            'subject_name' => 'CSAT Paper 2',
            'subject_code' => 'CSAT',
            'questions_count' => 50,
            'marks_per_question' => 2.00,
            'negative_marking' => 0.66,
            'max_marks' => 100.00,
        ],
    ];

    $test = $service->createTest($testData, $subjects);

    expect($test)->toBeInstanceOf(StudentTest::class)
        ->and($test->code)->toBe('TEST-2027-0001')
        ->and($test->subjects()->count())->toBe(2);

    // Auto-roster should have initialized records for both enrolled students
    $rosterCount = StudentTestResult::where('student_test_id', $test->id)->count();
    expect($rosterCount)->toBe(2);
});

test('mcq scoring calculator calculates positive, negative, net marks, and percentage correctly', function () {
    $service = app(StudentTestService::class);
    $calculator = app(TestScoreCalculatorService::class);

    $test = $service->createTest([
        'title' => 'Sample MCQ Prelims Exam',
        'code' => 'TEST-MCQ-CALC',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => null,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'total_marks' => 200.00,
        'total_questions' => 100,
        'marks_per_question' => 2.00,
        'negative_marking_ratio' => 0.33,
        'test_date' => now()->toDateString(),
    ]);

    $calculator->recordStudentScore($test, $this->student1->id, [
        'attendance_status' => 'present',
        'right_answers' => 70,
        'wrong_answers' => 10,
        'blank_answers' => 20,
    ]);

    $calculator->recordStudentScore($test, $this->student2->id, [
        'attendance_status' => 'present',
        'right_answers' => 50,
        'wrong_answers' => 20,
        'blank_answers' => 30,
    ]);

    $res1 = StudentTestResult::where('student_test_id', $test->id)->where('user_id', $this->student1->id)->first();
    $res2 = StudentTestResult::where('student_test_id', $test->id)->where('user_id', $this->student2->id)->first();

    expect((float) $res1->marks_obtained)->toBe(133.4)
        ->and((float) $res1->percentage)->toBe(66.7)
        ->and($res1->rank)->toBe(1)
        ->and($res1->is_top_ten)->toBeTrue()
        ->and((float) $res2->marks_obtained)->toBe(86.8)
        ->and((float) $res2->percentage)->toBe(43.4)
        ->and($res2->rank)->toBe(2);
});

test('student cannot download solutions before test conclude time but can download after conclusion', function () {
    $futureTest = StudentTest::create([
        'title' => 'Future Exam',
        'code' => 'TEST-FUTURE',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'test_date' => now()->addDay()->toDateString(),
        'start_time' => '10:00 AM',
        'end_time' => '12:00 PM',
        'duration_minutes' => 120,
        'total_marks' => 100.00,
        'total_questions' => 50,
        'question_paper_path' => 'tests/question_papers/paper_test.pdf',
        'status' => 'scheduled',
    ]);

    expect($futureTest->canDownloadSolutions($this->student1))->toBeFalse();

    $pastTest = StudentTest::create([
        'title' => 'Past Concluded Exam',
        'code' => 'TEST-PAST',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'test_date' => now()->subDay()->toDateString(),
        'start_time' => '10:00 AM',
        'end_time' => '12:00 PM',
        'duration_minutes' => 120,
        'total_marks' => 100.00,
        'total_questions' => 50,
        'question_paper_path' => 'tests/question_papers/paper_test.pdf',
        'status' => 'completed',
    ]);

    expect($pastTest->canDownloadSolutions($this->student1))->toBeTrue();
});

test('student cannot view another student marksheet but can view their own', function () {
    $test = StudentTest::create([
        'title' => 'Privacy Check Exam',
        'code' => 'TEST-PRIV',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'test_date' => now()->subDays(2)->toDateString(),
        'total_marks' => 100.00,
        'total_questions' => 50,
        'status' => 'completed',
    ]);

    $res1 = StudentTestResult::create([
        'student_test_id' => $test->id,
        'user_id' => $this->student1->id,
        'attendance_status' => 'present',
        'marks_obtained' => 85.00,
        'total_marks' => 100.00,
        'percentage' => 85.00,
        'rank' => 1,
    ]);

    $res2 = StudentTestResult::create([
        'student_test_id' => $test->id,
        'user_id' => $this->student2->id,
        'attendance_status' => 'present',
        'marks_obtained' => 70.00,
        'total_marks' => 100.00,
        'percentage' => 70.00,
        'rank' => 2,
    ]);

    // Student 1 requesting Student 2's marksheet -> 403 Forbidden
    $this->actingAs($this->student1)
        ->get(route('tests.download-marksheet', ['result' => $res2->id]))
        ->assertStatus(403);

    // Student 1 requesting their own marksheet -> 200 Stream Download
    $response = $this->actingAs($this->student1)
        ->get(route('tests.download-marksheet', ['result' => $res1->id]));

    $response->assertStatus(200);
    expect($response->headers->get('content-type'))->toBe('application/pdf');

    // Verify marksheet template contains computer-generated notice and no Controller of Examinations space
    $renderedHtml = view('exports.marksheet-pdf', [
        'result' => $res1,
        'test' => $test,
        'student' => $this->student1,
        'qrDataUri' => null,
        'generatedAt' => now(),
    ])->render();

    expect($renderedHtml)->toContain('computer-generated document and does not require a physical signature')
        ->and($renderedHtml)->not->toContain('Controller of Examinations');
});

test('participation report calculates 100 percent attendees and 0 percent absentees correctly', function () {
    $reportService = app(TestReportService::class);
    $calculator = app(TestScoreCalculatorService::class);

    $test = StudentTest::create([
        'title' => 'Participation Metric Test',
        'code' => 'TEST-PART',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'test_date' => now()->subDay()->toDateString(),
        'total_marks' => 100.00,
        'total_questions' => 50,
        'status' => 'completed',
    ]);

    // Student 1 attends
    $calculator->recordStudentScore($test, $this->student1->id, [
        'attendance_status' => 'present',
        'right_answers' => 40,
        'wrong_answers' => 5,
        'blank_answers' => 5,
    ]);

    // Student 2 is absent
    $calculator->recordStudentScore($test, $this->student2->id, [
        'attendance_status' => 'absent',
    ]);

    $analytics = $reportService->getParticipationAnalytics($this->batch->id, $this->course->id);

    expect($analytics['total_tests_conducted'])->toBe(1)
        ->and($analytics['participated_all_count'])->toBe(1)
        ->and($analytics['participated_all']->first()['user']->id)->toBe($this->student1->id)
        ->and($analytics['participated_none_count'])->toBe(1)
        ->and($analytics['participated_none']->first()['user']->id)->toBe($this->student2->id);
});

test('student tests rest api returns tests feed and personal performance summary', function () {
    $this->actingAs($this->student1);

    $response = $this->getJson(route('api.v1.student-tests.my-tests'));

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'summary' => [
                'total_tests_conducted',
                'attended_count',
                'absent_count',
                'participation_rate',
                'avg_percentage',
            ],
            'tests',
        ]);
});

test('admin can view test results gradebook and leaderboard pages', function () {
    $test = StudentTest::create([
        'title' => 'Gradebook Rendering Test',
        'code' => 'TEST-GB-01',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'test_date' => now()->toDateString(),
        'total_marks' => 100.00,
        'total_questions' => 50,
        'status' => 'completed',
    ]);

    $this->actingAs($this->admin);

    $this->get(route('admin.tests.results', ['test' => $test->id, 'current_team' => $this->team->slug]))
        ->assertStatus(200);

    $this->get(route('admin.tests.reports', ['current_team' => $this->team->slug]))
        ->assertStatus(200);

    $this->get(route('admin.tests.leaderboard', ['current_team' => $this->team->slug]))
        ->assertStatus(200);
});

test('student can access student tests portal and leaderboard', function () {
    $this->actingAs($this->student1);

    $test = StudentTest::create([
        'title' => 'UPSC GS Mock 1',
        'code' => 'UPSC-GS-01',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'total_marks' => 100.00,
        'total_questions' => 50,
        'test_date' => now()->subDay()->toDateString(),
        'status' => StudentTest::STATUS_COMPLETED,
    ]);

    StudentTestResult::create([
        'student_test_id' => $test->id,
        'user_id' => $this->student1->id,
        'attendance_status' => 'present',
        'total_marks' => 100.00,
        'marks_obtained' => 85.00,
        'percentage' => 85.00,
        'rank' => 1,
    ]);

    $this->get(route('student.tests.index', ['current_team' => $this->team->slug]))
        ->assertStatus(200);

    $this->get(route('student.tests.leaderboard', ['current_team' => $this->team->slug]))
        ->assertStatus(200);

    Livewire::actingAs($this->student1)
        ->test('pages::student.tests')
        ->assertOk()
        ->set('activeTab', 'leaderboard')
        ->assertSee(__('Rankers Podium'))
        ->assertSee('UPSC-GS-01')
        ->assertSee($this->student1->name);
});

test('test can be soft deleted and audited', function () {
    $service = app(StudentTestService::class);

    $test = $service->createTest([
        'title' => 'Test to be Deleted',
        'code' => 'TEST-DEL-01',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'total_marks' => 100.00,
        'total_questions' => 50,
        'test_date' => now()->toDateString(),
    ]);

    $testId = $test->id;
    $service->deleteTest($test);

    expect(StudentTest::find($testId))->toBeNull()
        ->and(StudentTest::withTrashed()->find($testId))->not->toBeNull();
});

test('upsc gs paper 1 and csat paper 2 marking schemes and qualifying cutoffs are accurately evaluated', function () {
    $service = app(StudentTestService::class);
    $calculator = app(TestScoreCalculatorService::class);

    // 1. UPSC GS Paper I: 100 Qs, 200 Marks, +2.00, 1/3rd penalty (-0.6667)
    $gsTest = $service->createTest([
        'title' => 'UPSC Prelims GS Paper I Official Standard Mock',
        'code' => 'TEST-UPSC-GS-OFFICIAL',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'total_marks' => 200.00,
        'pass_marks' => 88.00,
        'total_questions' => 100,
        'marks_per_question' => 2.00,
        'negative_marking_ratio' => 0.3333,
        'test_date' => now()->toDateString(),
    ]);

    expect($gsTest->isCsat())->toBeFalse()
        ->and($gsTest->penaltyPerWrongAnswer())->toBe(0.66);

    // Candidate A: 50 Right, 15 Wrong, 35 Blank
    // Positive: 50 * 2.00 = 100.00
    // Negative: 15 * (2.00 / 3) = 10.00
    // Net: 100.00 - 10.00 = 90.00
    $calculator->recordStudentScore($gsTest, $this->student1->id, [
        'attendance_status' => 'present',
        'right_answers' => 50,
        'wrong_answers' => 15,
        'blank_answers' => 35,
    ]);

    $resGs = StudentTestResult::where('student_test_id', $gsTest->id)->where('user_id', $this->student1->id)->first();
    expect((float) $resGs->positive_marks)->toBe(100.0)
        ->and((float) $resGs->negative_marks)->toBe(9.9)
        ->and((float) $resGs->marks_obtained)->toBe(90.1)
        ->and($resGs->is_passed)->toBeTrue();

    // 2. UPSC CSAT Paper II: 80 Qs, 200 Marks, +2.50, 1/3rd penalty (-0.83 Marks), Qualifying cutoff: 66.67 Marks (33.33%)
    $csatTest = $service->createTest([
        'title' => 'UPSC Prelims CSAT Paper II Qualifying Mock',
        'code' => 'TEST-UPSC-CSAT-OFFICIAL',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'total_marks' => 200.00,
        'pass_marks' => 66.67,
        'total_questions' => 80,
        'marks_per_question' => 2.50,
        'negative_marking_ratio' => 0.33,
        'test_date' => now()->toDateString(),
    ]);

    expect($csatTest->isCsat())->toBeTrue()
        ->and($csatTest->isQualifyingOnly())->toBeTrue()
        ->and($csatTest->penaltyPerWrongAnswer())->toBe(0.83);

    // Candidate 1: 30 Right, 9 Wrong, 41 Blank
    // Positive: 30 * 2.50 = 75.00
    // Negative: 9 * 0.83 = 7.47
    // Net: 75.00 - 7.47 = 67.53 (Qualifies >= 66.67)
    $calculator->recordStudentScore($csatTest, $this->student1->id, [
        'attendance_status' => 'present',
        'right_answers' => 30,
        'wrong_answers' => 9,
        'blank_answers' => 41,
    ]);

    // Candidate 2: 28 Right, 6 Wrong, 46 Blank
    // Positive: 28 * 2.50 = 70.00
    // Negative: 6 * 0.83 = 4.98
    // Net: 70.00 - 4.98 = 65.02 (Fails < 66.67)
    $calculator->recordStudentScore($csatTest, $this->student2->id, [
        'attendance_status' => 'present',
        'right_answers' => 28,
        'wrong_answers' => 6,
        'blank_answers' => 46,
    ]);

    $resCsat1 = StudentTestResult::where('student_test_id', $csatTest->id)->where('user_id', $this->student1->id)->first();
    $resCsat2 = StudentTestResult::where('student_test_id', $csatTest->id)->where('user_id', $this->student2->id)->first();

    expect((float) $resCsat1->marks_obtained)->toBe(67.53)
        ->and($resCsat1->is_passed)->toBeTrue()
        ->and((float) $resCsat2->marks_obtained)->toBe(65.02)
        ->and($resCsat2->is_passed)->toBeFalse();
});

test('leaderboard rankers limit is configurable and respects admin settings', function () {
    $service = app(StudentTestService::class);
    $calculator = app(TestScoreCalculatorService::class);

    $test = $service->createTest([
        'title' => 'GS Merit Ranking Championship',
        'code' => 'TEST-MERIT-LIMIT',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'total_marks' => 200.00,
        'total_questions' => 100,
        'marks_per_question' => 2.00,
        'test_date' => now()->toDateString(),
    ]);

    // Create 5 students with distinct scores
    for ($i = 1; $i <= 5; $i++) {
        $student = User::factory()->create([
            'name' => "Student Ranker {$i}",
            'email' => "ranker{$i}@example.com",
            'student_id' => "STU-RANK-00{$i}",
        ]);
        $student->assignRole('Student');

        $calculator->recordStudentScore($test, $student->id, [
            'attendance_status' => 'present',
            'right_answers' => 80 - ($i * 5),
            'wrong_answers' => 10,
            'blank_answers' => 10 + ($i * 5),
        ]);
    }

    // Default limit
    expect($test->rankers(3)->count())->toBe(3)
        ->and($test->rankers(5)->count())->toBe(5);

    // Save and retrieve setting
    Setting::set('test_leaderboard_limit', 5);
    expect((int) Setting::get('test_leaderboard_limit', 10))->toBe(5);
});

test('candidate scores can be imported with remarks, feedback, evaluated_by and evaluated_at', function () {
    $service = app(StudentTestService::class);
    $importService = app(TestImportExportService::class);

    $test = $service->createTest([
        'title' => 'Import Evaluation Detailed Test',
        'code' => 'TEST-IMPORT-FEEDBACK',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'total_marks' => 200.00,
        'total_questions' => 100,
        'marks_per_question' => 2.00,
        'test_date' => now()->toDateString(),
    ]);

    $evaluator = User::factory()->create([
        'name' => 'Prof. S. Mukherjee',
        'email' => 'prof.mukherjee@sntcssc.in',
    ]);

    // Prepare CSV with new columns: remarks, feedback, evaluated_by, evaluated_at
    $csvContent = "roll_number,student_name,email,attendance_status,right_answers,wrong_answers,blank_answers,marks_obtained,remarks,feedback,evaluated_by,evaluated_at\n";
    $csvContent .= "ROLL-001,Aarav Sharma,aarav@example.com,present,60,12,28,112.08,\"Excellent analytical attempt\",\"Focus on CSAT Reading Comprehension speed\",prof.mukherjee@sntcssc.in,2026-10-28 14:30\n";
    $csvContent .= "ROLL-002,Ishita Sen,ishita@example.com,present,40,24,36,64.16,\"Needs revision in Modern Indian History\",\"Consistently practice mock test timing\",prof.mukherjee@sntcssc.in,2026-10-28 15:00\n";

    $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_');
    file_put_contents($tmpFile, $csvContent);

    $uploadedFile = new UploadedFile(
        $tmpFile,
        'candidate-scores.csv',
        'text/csv',
        null,
        true
    );

    $result = $importService->importScores($test, $uploadedFile);

    expect($result['success'])->toBeTrue()
        ->and($result['imported'] + $result['updated'])->toBe(2);

    $res1 = StudentTestResult::where('student_test_id', $test->id)->where('user_id', $this->student1->id)->first();
    $res2 = StudentTestResult::where('student_test_id', $test->id)->where('user_id', $this->student2->id)->first();

    expect($res1)->not->toBeNull()
        ->and($res1->remarks)->toBe('Excellent analytical attempt')
        ->and($res1->feedback)->toBe('Focus on CSAT Reading Comprehension speed')
        ->and($res1->evaluated_by)->toBe($evaluator->id)
        ->and($res1->evaluated_at->format('Y-m-d H:i'))->toBe('2026-10-28 14:30')
        ->and($res2->remarks)->toBe('Needs revision in Modern Indian History')
        ->and($res2->feedback)->toBe('Consistently practice mock test timing')
        ->and($res2->evaluated_by)->toBe($evaluator->id);

    @unlink($tmpFile);
});

test('examination reports can be exported across all formats', function () {
    $service = app(StudentTestService::class);
    $calculator = app(TestScoreCalculatorService::class);
    $exportService = app(TestImportExportService::class);
    $reportService = app(TestReportService::class);

    $test = $service->createTest([
        'title' => 'Export Comprehensive Report Exam',
        'code' => 'TEST-EXPORT-ALL',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'total_marks' => 200.00,
        'total_questions' => 100,
        'marks_per_question' => 2.00,
        'test_date' => now()->toDateString(),
    ]);

    $calculator->recordStudentScore($test, $this->student1->id, [
        'attendance_status' => 'present',
        'right_answers' => 65,
        'wrong_answers' => 10,
        'blank_answers' => 25,
    ]);

    // Test Wise Exports
    $csvExport = $exportService->exportTestResults($test, 'csv');
    expect($csvExport)->toBeInstanceOf(StreamedResponse::class);

    $pdfExport = $exportService->exportTestResults($test, 'pdf');
    expect($pdfExport)->toBeInstanceOf(StreamedResponse::class);

    // Student Dossier Export
    $dossier = $reportService->getStudentOverallReport($this->student1->id);
    $dossierCsv = $exportService->exportStudentDossier($this->student1, $dossier, 'csv');
    expect($dossierCsv)->toBeInstanceOf(StreamedResponse::class);

    $dossierPdf = $exportService->exportStudentDossier($this->student1, $dossier, 'pdf');
    expect($dossierPdf)->toBeInstanceOf(StreamedResponse::class);

    // Participation Audit Export
    $partData = $reportService->getParticipationAnalytics();
    $partCsv = $exportService->exportParticipationAudit($partData, 'csv');
    expect($partCsv)->toBeInstanceOf(StreamedResponse::class);

    // Section Comparison Export
    $secData = $reportService->getSectionComparisonStats($this->course->id);
    $secCsv = $exportService->exportSectionComparison($secData, 'csv');
    expect($secCsv)->toBeInstanceOf(StreamedResponse::class);
});

test('admin can access leaderboard and reports pages with Livewire reactivity', function () {
    $this->actingAs($this->admin);

    $this->get(route('admin.tests.leaderboard', ['current_team' => $this->team->slug]))->assertOk();
    $this->get(route('admin.tests.reports', ['current_team' => $this->team->slug]))->assertOk();

    Livewire::test('pages::admin.tests.leaderboard')
        ->set('topLimit', 5)
        ->call('saveDefaultLimit')
        ->assertDispatched('toast');

    expect((int) Setting::get('test_leaderboard_limit'))->toBe(5);

    Livewire::test('pages::admin.tests.reports')
        ->set('activeTab', 'participation')
        ->assertSee('Participation Champions')
        ->set('activeTab', 'sections')
        ->assertSee('Classroom Code');
});

test('update candidate score modal allows modifying attendance status and roster fields', function () {
    $this->actingAs($this->admin);
    $calculator = app(TestScoreCalculatorService::class);

    $test = StudentTest::create([
        'title' => 'Modal Update Verification Test',
        'code' => 'TEST-MODAL-UPD',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $this->sectionA->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'total_marks' => 200.00,
        'total_questions' => 100,
        'marks_per_question' => 2.00,
        'negative_marking_ratio' => 0.3333,
        'test_date' => now()->toDateString(),
    ]);

    $result = $calculator->recordStudentScore($test, $this->student1->id, [
        'attendance_status' => 'present',
        'right_answers' => 60,
        'wrong_answers' => 20,
        'blank_answers' => 20,
    ]);

    // 1. Change attendance to Absent via modal action
    Livewire::test('pages::admin.tests.results', ['test' => $test])
        ->call('openEditResultModal', $result->id)
        ->assertSet('editForm.attendance_status', 'present')
        ->call('setAttendanceStatus', 'absent')
        ->assertSet('editForm.attendance_status', 'absent')
        ->call('saveResult')
        ->assertDispatched('toast');

    $result->refresh();
    expect($result->attendance_status)->toBe('absent')
        ->and((float) $result->marks_obtained)->toBe(0.00)
        ->and($result->rank)->toBeNull();

    $sectionB = Section::create([
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'name' => 'Section Beta',
        'code' => 'SEC-B',
        'capacity' => 60,
        'status' => 'active',
    ]);

    // 2. Change attendance back to Present and correct roll number, section, manual override marks, and feedback
    Livewire::test('pages::admin.tests.results', ['test' => $test])
        ->call('openEditResultModal', $result->id)
        ->call('setAttendanceStatus', 'present')
        ->set('editForm.roll_number', 'ROLL-CORRECTED-99')
        ->set('editForm.section_id', (string) $sectionB->id)
        ->set('editForm.right_answers', 85)
        ->set('editForm.wrong_answers', 5)
        ->set('editForm.manual_override_marks', 170.00)
        ->set('editForm.remarks', 'Corrected after verification of OMR.')
        ->set('editForm.feedback', 'Outstanding conceptual retention.')
        ->call('saveResult')
        ->assertDispatched('toast');

    $result->refresh();
    expect($result->attendance_status)->toBe('present')
        ->and($result->roll_number)->toBe('ROLL-CORRECTED-99')
        ->and($result->section_id)->toBe($sectionB->id)
        ->and((float) $result->marks_obtained)->toBe(170.00)
        ->and($result->remarks)->toBe('Corrected after verification of OMR.')
        ->and($result->feedback)->toBe('Outstanding conceptual retention.')
        ->and($result->rank)->toBe(1);

    // 3. Clear manual override and verify formula auto-calculation restores
    Livewire::test('pages::admin.tests.results', ['test' => $test])
        ->call('openEditResultModal', $result->id)
        ->assertSet('editForm.manual_override_marks', 170.00)
        ->set('editForm.manual_override_marks', '')
        ->call('saveResult')
        ->assertDispatched('toast');

    $result->refresh();
    // UPSC GS I Standard: 85 * 2.00 - 5 * 0.66 = 170.00 - 3.30 = 166.70
    expect((float) $result->marks_obtained)->toBe(166.70)
        ->and($result->attendance_status)->toBe('present')
        ->and($result->rank)->toBe(1);
});

test('api endpoints provide complete student test management, dashboard, rankers, and marksheets', function () {
    $calculator = app(TestScoreCalculatorService::class);

    $test = StudentTest::create([
        'title' => 'API Verification Mock Test',
        'code' => 'TEST-API-VERIF',
        'batch_id' => $this->batch->id,
        'course_id' => $this->course->id,
        'section_id' => $this->sectionA->id,
        'test_stage' => 'prelims',
        'test_type' => 'mcq',
        'test_for' => 'mock_test',
        'total_marks' => 200.00,
        'total_questions' => 100,
        'marks_per_question' => 2.00,
        'negative_marking_ratio' => 0.3333,
        'test_date' => now()->toDateString(),
    ]);

    $calculator->recordStudentScore($test, $this->student1->id, [
        'attendance_status' => 'present',
        'right_answers' => 70,
        'wrong_answers' => 15,
        'blank_answers' => 15,
        'remarks' => 'Consistent performance in GS.',
        'feedback' => 'Revise CSAT comprehension speed.',
    ]);

    $result = $test->results()->where('user_id', $this->student1->id)->first();

    // 1. Dashboard API
    $dashRes = $this->getJson(route('api.v1.student-tests.dashboard'));
    $dashRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'summary' => ['total_tests', 'completed_tests', 'upcoming_tests', 'overall_attendance_rate'],
            'distribution' => ['by_stage', 'by_type'],
            'recent_tests',
            'top_achievers',
        ]);

    // 2. Global Rankers API
    $rankersRes = $this->getJson(route('api.v1.student-tests.rankers', ['limit' => 5]));
    $rankersRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('limit', 5)
        ->assertJsonStructure(['rankers' => [['rank', 'student_id', 'student_name', 'total_marks', 'average_percentage']]]);

    // 3. Test-specific Leaderboard API with custom limit
    $leaderboardRes = $this->getJson(route('api.v1.student-tests.leaderboard', ['identifier' => $test->code, 'limit' => 3]));
    $leaderboardRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('limit', 3)
        ->assertJsonStructure(['leaderboard' => [['rank', 'student_id', 'student_name', 'student_avatar', 'marks_obtained']]]);

    // 4. Section Comparison API
    $secCompRes = $this->getJson(route('api.v1.student-tests.section-comparison', ['identifier' => $test->id]));
    $secCompRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['sections' => [['section_name', 'enrolled_count', 'attended_count', 'avg_marks']]]);

    // 5. Candidate Marksheet API
    $marksheetRes = $this->getJson(route('api.v1.student-tests.marksheet', ['resultId' => $result->id]));
    $marksheetRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('result.roll_number', 'ROLL-001')
        ->assertJsonPath('result.right_answers', 70)
        ->assertJsonPath('candidate.name', 'Aarav Sharma')
        ->assertJsonStructure(['result' => ['download_pdf_url']]);

    // 6. Multipart Score Import API
    $csvContent = "roll_number,student_name,email,attendance_status,right_answers,wrong_answers,blank_answers,marks_obtained,remarks,feedback\n";
    $csvContent .= "ROLL-001,Aarav Sharma,aarav@example.com,present,80,10,10,,Excellent,Maintain consistency\n";
    $file = UploadedFile::fake()->createWithContent('scores.csv', $csvContent);

    $importRes = $this->postJson(route('api.v1.student-tests.import-scores', ['identifier' => $test->code]), [
        'file' => $file,
    ]);
    $importRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('imported_count', 1);

    // Verify marks updated via import
    $result->refresh();
    expect((int) $result->right_answers)->toBe(80)
        ->and((int) $result->wrong_answers)->toBe(10);
});
